<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_FullTextSearch_Tesseract\Service;


use Exception;
use Imagick;
use OC\Files\View;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files_FullTextSearch\Model\AFilesDocument;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchRequest;
use Psr\Log\LoggerInterface;
use Spatie\PdfToImage\Exceptions\PageDoesNotExist;
use Spatie\PdfToImage\Pdf;
use thiagoalessio\TesseractOCR\FriendlyErrors;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Throwable;


/**
 * Class TesseractService
 *
 * @package OCA\Files_FullTextSearch_Tesseract\Service
 */
class TesseractService {

	/** Imagick decoders for the image types that can be rotated before OCR */
	private const IMAGICK_FORMATS = [
		'image/png' => 'png',
		'image/jpeg' => 'jpeg',
		'image/tiff' => 'tiff',
	];

	public function __construct(
		private ConfigService $configService,
		private LoggerInterface $logger
	) {
	}


	/**
	 * @param string $mimeType
	 * @param string $extension
	 *
	 * @return bool
	 */
	public function parsedMimeType(string $mimeType, string $extension): bool {
		$ocrMimes = [
			'image/png',
			'image/jpeg',
			'image/tiff',
			'image/vnd.djvu',
			'application/pdf'
		];

		if ($this->isHeicSupported()) {
			$ocrMimes[] = 'image/heic';
			$ocrMimes[] = 'image/heif';
		}

		foreach ($ocrMimes as $mime) {
			if (strpos($mimeType, $mime) === 0) {
				return true;
			}
		}

		if ($mimeType === 'application/octet-stream') {
			return $this->parsedExtension($extension);
		}

		return false;
	}


	/**
	 * @param GenericEvent $e
	 */
	public function onFileIndexing(GenericEvent $e): void {
		/** @var Node $file */
		$file = $e->getArgument('file');

		if (!$file instanceof File) {
			return;
		}

		/** @var \OCP\Files_FullTextSearch\Model\AFilesDocument $document */
		$document = $e->getArgument('document');

		$this->extractContentUsingTesseractOCR($document, $file);
	}


	/**
	 * @param GenericEvent $e
	 */
	public function onSearchRequest(GenericEvent $e) {
		/** @var ISearchRequest $file */
		$request = $e->getArgument('request');
		$request->addPart('ocr');
	}


	/**
	 * @param AFilesDocument $document
	 * @param File $file
	 */
	private function extractContentUsingTesseractOCR(AFilesDocument &$document, File $file): void {
		try {
			if ($this->configService->getAppValue(ConfigService::TESSERACT_ENABLED) !== '1') {
				return;
			}

			$extension = pathinfo($document->getPath(), PATHINFO_EXTENSION);

			if (!$this->parsedMimeType($document->getMimetype(), $extension)) {
				return;
			}

			$this->logger->debug(
				'extracting content using TesseractOCR',
				[
					'documentId' => $document->getId(),
					'path' => $document->getPath(),
					'mime' => $document->getMimetype(),
					'extension' => $extension
				]
			);

			// TODO: How to set options so that the index can be reset if admin settings are changed
			//	$this->configService->setDocumentIndexOption($document, ConfigService::FILES_OCR);

			if ($this->ocrPdf($document, $file)) {
				return;
			}

			$content = $this->ocrFile($file);
		} catch (Throwable $e) {
			return;
		}

		$document->setContent(base64_encode($content), IIndexDocument::ENCODED_BASE64);
	}


	/**
	 * @param File $file
	 *
	 * @return string
	 * @throws NotFoundException
	 */
	private function ocrFile(File $file): string {
		try {
			$path = $this->getAbsolutePath($file);
		} catch (Exception $e) {
			$this->logger->notice('issue during ocrFile()', ['exception' => $e]);
			throw new NotFoundException();
		}

		if (in_array($file->getMimetype(), ['image/heic', 'image/heif'], true)) {
			return $this->ocrHeic($path);
		}

		return $this->ocrImage($path, self::IMAGICK_FORMATS[$file->getMimetype()] ?? null);
	}


	/**
	 * @return bool
	 */
	private function isHeicSupported(): bool {
		return extension_loaded('imagick') && in_array('HEIC', Imagick::queryFormats('HEI*'), true);
	}


	/**
	 * Convert HEIC/HEIF to a temp PNM for tesseract.
	 *
	 * @param string $path
	 *
	 * @return string
	 */
	private function ocrHeic(string $path): string {
		$this->logger->debug('converting HEIC to a temp PNM', ['path' => $path]);

		$tmpFile = tmpfile();
		$tmpPath = stream_get_meta_data($tmpFile)['uri'];

		try {
			$image = new Imagick();
			// force the HEIC decoder, never one picked from the file content; [0] is the primary image
			$image->readImage('heic:' . $path . '[0]');
			$image->autoOrient();
			$image->setImageFormat('pnm');
			$image->writeImage('pnm:' . $tmpPath);
			$image->clear();
		} catch (Throwable $e) {
			$this->logger->notice('failed to convert HEIC', ['exception' => $e, 'path' => $path]);
			fclose($tmpFile);

			return '';
		}

		$content = $this->ocrImage($tmpPath, 'pnm');
		fclose($tmpFile);

		return $content;
	}


	/**
	 * If enabled and OSD reports a confident rotation, also OCR a rotated
	 * temp copy of the first page, and return both texts.
	 *
	 * @param string $path
	 * @param string|null $format Imagick decoder for the image, null if it cannot be rotated
	 *
	 * @return string
	 */
	private function ocrImage(string $path, ?string $format): string {
		if ($format === null
			|| $this->configService->getAppValue(ConfigService::TESSERACT_ROTATE) !== '1') {
			return $this->ocrFileFromPath($path);
		}

		$rotate = $this->detectRotation($path);
		if ($rotate === 0) {
			return $this->ocrFileFromPath($path);
		}

		$this->logger->debug('rotating image for OCR', ['path' => $path, 'rotate' => $rotate]);

		$tmpFile = tmpfile();
		$tmpPath = stream_get_meta_data($tmpFile)['uri'];

		try {
			$image = new Imagick();
			// force the decoder from the mimetype, never from the file content
			$image->readImage($format . ':' . $path . '[0]');
			$image->rotateImage('white', $rotate);
			$image->setImageFormat('pnm');
			$image->writeImage('pnm:' . $tmpPath);
			$image->clear();
		} catch (Throwable $e) {
			$this->logger->notice('failed to rotate image', ['exception' => $e, 'path' => $path]);
			fclose($tmpFile);

			return $this->ocrFileFromPath($path);
		}

		$content = implode("\n", $this->ocrFilesFromPaths([$tmpPath, $path]));
		fclose($tmpFile);

		return $content;
	}


	/**
	 * @param string $path
	 *
	 * @return int degrees to rotate clockwise, 0 if upright, unknown or below the configured confidence
	 */
	private function detectRotation(string $path): int {
		try {
			$osd = (new TesseractOCR($path))->psm(0)->withoutTempFiles()->run();
		} catch (Exception $e) {
			$this->logger->debug('could not detect orientation', ['path' => $path, 'error' => $e->getMessage()]);

			return 0;
		}

		if (!preg_match('/Rotate: (\d+)/', $osd, $rotate)
			|| !preg_match('/Orientation confidence: ([\d.]+)/', $osd, $confidence)) {
			return 0;
		}

		$minConfidence = (float)$this->configService->getAppValue(ConfigService::TESSERACT_ROTATE_CONFIDENCE);
		if ((float)$confidence[1] < $minConfidence) {
			$this->logger->debug('ignoring unsure orientation', ['path' => $path, 'rotate' => (int)$rotate[1], 'confidence' => (float)$confidence[1]]);

			return 0;
		}

		return (int)$rotate[1];
	}


	/**
	 * @param string $path
	 *
	 * @return string
	 */
	private function ocrFileFromPath(string $path): string {
		$this->logger->debug('generating the TesseractOCR wrapper', ['path' => $path]);

		$ocr = $this->newOcr($path);
		$lang = explode(',', $this->configService->getAppValue(ConfigService::TESSERACT_LANG));
		$this->logger->debug('running the OCR command', ['command' => $ocr->command]);

//		if ($this->configService->getLogLevel() > 0) {
//			$ocr->command .= ' 2> /dev/null';
//		}

		try {
			$result = $ocr->run();
			$this->logger->debug('OCR command ran smoothly');
		} catch (Exception $e) {
			$this->logger->notice('failed to OCR', [
				'exception' => $e,
				'path' => $path,
				'cmd' => $ocr->command,
				'lang' => $lang
			]);
			$result = '';
		}

		return $result;
	}


	/**
	 * Run the OCR of several images as parallel tesseract processes, each
	 * writing to its own temp files.
	 *
	 * @param string[] $paths
	 *
	 * @return string[] the text of each image, '' if its OCR failed
	 */
	private function ocrFilesFromPaths(array $paths): array {
		// build every command first: nothing may throw once a process is started
		$jobs = [];
		foreach ($paths as $path) {
			$jobs[] = [
				'path' => $path,
				'ocr' => $this->newOcr($path)->withoutTempFiles(),
				'out' => tmpfile(),
				'err' => tmpfile()
			];
		}

		foreach ($jobs as $i => $job) {
			$jobs[$i]['process'] = proc_open(
				(string)$job['ocr']->command,
				[['file', '/dev/null', 'r'], $job['out'], $job['err']],
				$pipes
			);
		}

		$results = [];
		foreach ($jobs as $job) {
			$result = '';
			if ($job['process'] === false) {
				$this->logger->notice('failed to start OCR', ['path' => $job['path'], 'cmd' => $job['ocr']->command]);
			} else {
				proc_close($job['process']);
				rewind($job['out']);
				rewind($job['err']);
				$out = stream_get_contents($job['out']);
				try {
					FriendlyErrors::checkCommandExecution($job['ocr']->command, $out, stream_get_contents($job['err']));
					// same trimming as TesseractOCR::run()
					$result = trim($out, " \t\n\r\0\x0A\x0B\x0C");
				} catch (Exception $e) {
					$this->logger->notice('failed to OCR', ['exception' => $e, 'path' => $job['path'], 'cmd' => $job['ocr']->command]);
				}
			}
			fclose($job['out']);
			fclose($job['err']);
			$results[] = $result;
		}

		return $results;
	}


	/**
	 * @param string $path
	 *
	 * @return TesseractOCR
	 */
	private function newOcr(string $path): TesseractOCR {
		$ocr = new TesseractOCR($path);
		$ocr->psm($this->configService->getAppValue(ConfigService::TESSERACT_PSM));
		$lang = explode(',', $this->configService->getAppValue(ConfigService::TESSERACT_LANG));
		call_user_func_array([$ocr, 'lang'], array_map('trim', $lang));

		return $ocr;
	}


	/**
	 * @param AFilesDocument $document
	 * @param File $file
	 *
	 * @return bool
	 * @throws NotFoundException
	 */
	private function ocrPdf(AFilesDocument $document, File $file): bool {
		if ($document->getMimetype() !== 'application/pdf') {
			return false;
		}

		if ($this->configService->getAppValue(ConfigService::TESSERACT_PDF) !== '1') {
			return true;
		}

		$this->logger->debug('looks like we\'re working on a PDF file');

		try {
			$path = $this->getAbsolutePath($file);
			$this->logger->debug('Absolute path', ['path' => $path]);
			$pdf = new Pdf($path);
		} catch (Exception $e) {
			$this->logger->notice('failed to ocrPdf', ['exception' => $e, 'document' => $document]);
			throw new NotFoundException();
		}

		$content = '';
		$pages = $pdf->getNumberOfPages();
		$this->logger->debug('PDF contains ' . $pages . ' page(s)');

		$limit = (int)$this->configService->getAppValue(ConfigService::TESSERACT_PDF_LIMIT);
		$pages = ($limit > 0 && $pages > $limit) ? $limit : $pages;
		$this->logger->debug('App will now ocr ' . $pages . ' page(s)');


		for ($i = 1; $i <= $pages; $i++) {
			$this->logger->debug('Creating a temp image file for page #' . $i);

			$tmpFile = tmpfile();
			$tmpPath = stream_get_meta_data($tmpFile)['uri'];
			$this->logger->debug('temp image file: ' . $tmpPath . ' for page #' . $i);

			try {
				$this->logger->debug('opening the PDF at the page #' . $i);
				$pdf->setPage($i);

				$this->logger->debug('saving the current page as image', ['tmpPath' => $tmpPath]);
				$pdf->saveImage($tmpPath);

				$content .= $this->ocrFileFromPath($tmpPath);
			} catch (PageDoesNotExist $e) {
			}

			fclose($tmpFile);
		}

		$this->logger->debug('Saving the data into the IndexDocument');
		$document->addPart('ocr', $content);

		return true;
	}


	/**
	 * @param string $extension
	 *
	 * @return bool
	 */
	private function parsedExtension(string $extension): bool {
		$ocrExtensions = [
//					'djvu'
		];

		if (in_array($extension, $ocrExtensions)) {
			return true;
		}

		return false;
	}


	/**
	 * @param File $file
	 *
	 * @return string
	 * @throws Exception
	 */
	private function getAbsolutePath(File $file): string {
		$view = new View('');

		return $view->getLocalFile($file->getPath());
	}
}
