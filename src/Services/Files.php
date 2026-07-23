<?php

namespace Gaia\Clarity\Services;

use Gaia\Clarity\Services\DB;
use Ramsey\Uuid\Uuid;

/**
 * Uploader class for handling file uploads.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

final class Files
{
    private array $files = [];

    // Allowed MIME types (can be extended)
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv'
    ];

    // Maximum file size in bytes (default: 10MB)
    private const MAX_FILE_SIZE = 10485760;

    /**
     * Validate file type
     *
     * @param string $mime_type The MIME type to validate.
     * @return bool True if valid, false otherwise.
     */
    private function isValidMimeType(string $mime_type): bool
    {
        return in_array($mime_type, self::ALLOWED_MIME_TYPES, true);
    }

    /**
     * Validate file size
     *
     * @param int $size The file size in bytes.
     * @return bool True if valid, false otherwise.
     */
    private function isValidFileSize(int $size): bool
    {
        return $size > 0 && $size <= self::MAX_FILE_SIZE;
    }

    /**
     * Sanitize filename to prevent path traversal
     *
     * @param string $filename The filename to sanitize.
     * @return string The sanitized filename.
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove path traversal attempts and dangerous characters
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return $filename;
    }

    public function store(array $files, mixed $parent_id): void
    {
        if (!empty($files)) {
            $this->removeEmptyUploads($files);

            if (!empty($this->files['tmp_name'])) {
                $this->storeFileMetaData($parent_id);
                $this->moveUploadedFile();
            }
        }
    }

    public function unlinkFileByParentId(mixed $parent_id): void
    {
        if (is_array($parent_id)) {
            $sql = 'SELECT filename FROM files WHERE parent_id IN (' . implode(',', array_fill(0, count($parent_id), '?')) . ');';
            $stmt = DB::run($sql, array_values($parent_id));
            $files_to_unlink = $stmt !== null ? $stmt->fetchAll() : [];

            foreach ($files_to_unlink as $file) {
                if (file_exists(PATH['upload'] . $file['filename'])) {
                    unlink(PATH['upload'] . $file['filename']);
                }
            }

            $sql = 'DELETE FROM files WHERE parent_id IN (' . implode(',', array_fill(0, count($parent_id), '?')) . ');';
            DB::run($sql, array_values($parent_id));
        } else {
            $sql = 'SELECT filename FROM files WHERE parent_id = :parent_id;';
            $stmt = DB::run($sql, ['parent_id' => $parent_id]);
            $files_to_unlink = $stmt !== null ? $stmt->fetchAll() : [];

            foreach ($files_to_unlink as $file) {
                if (file_exists(PATH['upload'] . $file['filename'])) {
                    unlink(PATH['upload'] . $file['filename']);
                }
            }

            $sql = 'DELETE FROM files WHERE parent_id = :parent_id;';
            DB::run($sql, ['parent_id' => $parent_id]);
        }
    }

    public function unlinkFileByFilename(mixed $filename): void
    {
        if (is_array($filename)) {
            foreach ($filename as $file) {
                $sanitized_file = $this->sanitizeFilename($file);
                $file_path = PATH['upload'] . $sanitized_file;
                if (file_exists($file_path) && strpos(realpath($file_path), realpath(PATH['upload'])) === 0) {
                    unlink($file_path);
                }
            }
        } else {
            $sanitized_file = $this->sanitizeFilename($filename);
            $file_path = PATH['upload'] . $sanitized_file;
            if (file_exists($file_path) && strpos(realpath($file_path), realpath(PATH['upload'])) === 0) {
                unlink($file_path);
            }
        }
    }

    public function getFileListByParentId(mixed $parent_id): array
    {
        $sql = 'SELECT id, is_primary, sequence, filename, original_filename ';
        $sql .= 'FROM files WHERE parent_id = :parent_id ORDER BY sequence ASC, created DESC;';
        $stmt = DB::run($sql, ['parent_id' => $parent_id]);
        return $stmt !== null ? $stmt->fetchAll() : [];
    }

    private function storeFileMetaData(mixed $parent_id): void
    {
        if (is_array($this->files['tmp_name'])) {
            for ($i = 0; $i < count($this->files['tmp_name']); $i++) {
                DB::insert('files', [
                    'id' => Uuid::uuid4()->toString(),
                    'parent_id' => $parent_id,
                    'mime_type' => $this->files['type'][$i],
                    'filename' => $this->files['new_name'][$i],
                    'original_filename' => $this->files['name'][$i]
                ]);
            }
        } else {
            DB::insert('files', [
                'id' => Uuid::uuid4()->toString(),
                'parent_id' => $parent_id,
                'mime_type' => $this->files['type'],
                'filename' => $this->files['new_name'],
                'original_filename' => $this->files['name']
            ]);
        }
    }

    private function moveUploadedFile(): void
    {
        if (is_array($this->files['tmp_name'])) {
            for ($i = 0; $i < count($this->files['tmp_name']); $i++) {
                move_uploaded_file($this->files['tmp_name'][$i], PATH['upload'] . '/' . $this->files['new_name'][$i]);
            }
        } else {
            move_uploaded_file($this->files['tmp_name'], PATH['upload'] . '/' . $this->files['new_name']);
        }
    }

    private function removeEmptyUploads(array $files): void
    {
        if (is_array($files['tmp_name'])) {
            $n = 0;

            for ($i = 0; $i < count($files); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    // Validate file before processing
                    if (!$this->isValidMimeType($files['type'][$i])) {
                        continue; // Skip invalid file types
                    }
                    if (!$this->isValidFileSize($files['size'][$i])) {
                        continue; // Skip files that are too large
                    }

                    $sanitized_name = $this->sanitizeFilename($files['name'][$i]);
                    $this->files['name'][$n] = $sanitized_name;
                    $this->files['full_path'][$n] = $files['full_path'][$i] ?? '';
                    $this->files['extension'][$n] = strtolower(end(explode('.', $sanitized_name)));
                    $this->files['new_name'][$n] = sha1($sanitized_name) . '.' . $this->files['extension'][$n];
                    $this->files['type'][$n] = $files['type'][$i];
                    $this->files['size'][$n] = $files['size'][$i];
                    $this->files['tmp_name'][$n] = $files['tmp_name'][$i];
                    $this->files['error'][$n] = $files['error'][$i];
                    $n++;
                }
            }
        } else {
            if ($files['error'] === UPLOAD_ERR_OK) {
                // Validate file before processing
                if (!$this->isValidMimeType($files['type'])) {
                    return; // Skip invalid file type
                }
                if (!$this->isValidFileSize($files['size'])) {
                    return; // Skip file that is too large
                }

                $sanitized_name = $this->sanitizeFilename($files['name']);
                $this->files['name'] = $sanitized_name;
                $this->files['full_path'] = $files['full_path'] ?? '';
                $this->files['extension'] = strtolower(end(explode('.', $sanitized_name)));
                $this->files['new_name'] = sha1($sanitized_name) . '.' . $this->files['extension'];
                $this->files['type'] = $files['type'];
                $this->files['size'] = $files['size'];
                $this->files['tmp_name'] = $files['tmp_name'];
                $this->files['error'] = $files['error'];
            }
        }
    }
}
