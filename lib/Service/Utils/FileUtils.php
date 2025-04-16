<?php

namespace OCA\Google\Service\Utils;

use OCP\Files\FileNameTooLongException;
use OCP\Files\EmptyFileNameException;
use OCP\Files\InvalidCharacterInPathException;
use OCP\Files\InvalidDirectoryException;
use OCP\Files\ReservedWordException;
use OCP\Files\InvalidPathException;
use Psr\Log\LoggerInterface;
use OC;

class FileUtils {
    
    /**
	 * Sanitize the filename to ensure it is valid, does not exceed length limits.
	 *
	 * @param string $filename The original filename to sanitize.
	 * @param string $id A unique ID to append if necessary to ensure uniqueness.
	 * @param int $recursionDepth The current recursion depth (used to prevent infinite loops).
	 * @return string The sanitized and validated filename.
	 */
    public static function sanitizeFilename(
        string $filename,
        string $id,
        LoggerInterface $logger,
        int $recursionDepth = 0,
        string $originalFilename = null
    ): string {
        // Prevent infinite recursion by limiting the depth.
        if ($recursionDepth > 15) {
            $filename = 'Untitled_' . $id;
            $logger->warning('Maximum recursion depth reached while sanitizing filename: ' . $originalFilename . ' renaming to ' . $filename);
            return $filename;
        }

        // If the original filename is not provided, use the current filename.
        if ($originalFilename === null) {
            $originalFilename = $filename;
        }

        // Trim leading/trailing whitespace and trailing dots.
        $filename = rtrim(trim($filename), '.');

        // Check if trimming altered the filename.
        $trimmed = ($originalFilename !== $filename);

        // Helper function to append the ID before the file extension.
        $appendIdBeforeExtension = function ($filename, $id) {
            $pathInfo = pathinfo($filename);
            if (isset($pathInfo['extension'])) {
                return $pathInfo['filename'] . '_' . $id . '.' . $pathInfo['extension'];
            } else {
                return $filename . '_' . $id;
            }
        };

        // Append the ID if trimming occurred and the ID is not already present.
        if ($trimmed && !str_contains($filename, $id)) {
            $filename = $appendIdBeforeExtension($filename, $id);
        }

        // Ensure the filename length does not exceed the maximum allowed length.
        $maxLength = 254;
        if (mb_strlen($filename) > $maxLength) {
            $pathInfo = pathinfo($filename);
            $baseLength = $maxLength - mb_strlen($id) - 2; // Account for '_' and '.'.
            if (isset($pathInfo['extension'])) {
                $baseLength -= mb_strlen($pathInfo['extension']);
                $filename = mb_substr($pathInfo['filename'], 0, $baseLength) . '_' . $id . '.' . $pathInfo['extension'];
            } else {
                $filename = mb_substr($filename, 0, $baseLength) . '_' . $id;
            }
        }

        try {
            // Validate the filename using the Nextcloud filename validator.
            \OC::$server->get(\OCP\Files\IFilenameValidator::class)->validateFilename($filename);

            // if recursion depth is greater than 0, log the change.
            if ($recursionDepth > 0) {
                $logger->info('Filename sanitized successfully: "' . $filename . '" (original: "' . $originalFilename . '")');
            }

            return $filename;
        } catch (InvalidPathException $exception) {
            $logger->warning('Invalid filename detected during sanitization: ' . $filename, ['exception' => $exception]);
        }

        // Handle specific exceptions and adjust the filename accordingly.
        switch (true) {
            case $exception instanceof FileNameTooLongException:
                $filename = mb_substr($filename, 0, $maxLength - mb_strlen($id) - 2);
                break;

            case $exception instanceof EmptyFileNameException:
                $filename = 'Untitled';
                break;

            case $exception instanceof InvalidCharacterInPathException:
                if (preg_match('/"(.*?)"/', $exception->getMessage(), $matches)) {
                    $invalidChars = array_merge(str_split($matches[1]), ['"']);
                    $filename = str_replace($invalidChars, '-', $filename);
                }
                break;

            case $exception instanceof InvalidDirectoryException:
                $logger->error('Invalid directory detected in filename: ' . $exception->getMessage());
                $filename = 'Untitled';
                break;

            case $exception instanceof ReservedWordException:
                if (preg_match('/"(.*?)"/', $exception->getMessage(), $matches)) {
                    $reservedWord = $matches[1];
                    $filename = str_ireplace($reservedWord, '-' . $reservedWord . '-', $filename);
                }
                break;

            default:
                $logger->error('Unknown exception encountered during filename sanitization: ' . $filename);
                $filename = 'Untitled';
                break;
        }

        // Append the ID if the filename was modified and does not already contain the ID.
        if (!str_contains($filename, $id)) {
            $filename = $appendIdBeforeExtension($filename, $id);
        }

        // Recursively validate the adjusted filename.
        return self::sanitizeFilename($filename, $id, $logger, $recursionDepth + 1, $originalFilename);
    }
}