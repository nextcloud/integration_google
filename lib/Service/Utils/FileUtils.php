<?php

namespace OCA\Google\Service\Utils;

use OCP\Files\IFilenameValidator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

final class FileUtils {

	public function __construct(
		private IFilenameValidator $validator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Sanitize the filename to ensure it is valid, does not exceed length limits.
	 *
	 * @param string $filename The original filename to sanitize.
	 * @param string $id A unique ID to append if necessary to ensure uniqueness.
	 * @return string The sanitized and validated filename.
	 */
	public function sanitizeFilename(
		string $filename,
		string $id,
	): string {

		// Use Nextcloud 32+ validator
		try {
			return $this->validator->sanitizeFilename($filename);
		} catch (\InvalidArgumentException|NotFoundExceptionInterface|ContainerExceptionInterface $exception) {
			$this->logger->error('Unable to sanitize filename: ' . $filename, ['exception' => $exception]);
			return 'Untitled_' . $id;
		}
	}
}
