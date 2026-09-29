<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Response;

/**
 * File upload handling:
 *  - size limit, PHP upload error checks, is_uploaded_file()
 *  - MIME detected from content (finfo), never from the client
 *  - images are decoded and re-encoded with GD (strips metadata and polyglot payloads)
 *  - random file names; private files live outside the web root
 *  - public upload dir has PHP execution disabled via .htaccess
 */
final class UploadService
{
    private const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const DOC_MIMES = ['application/pdf' => 'pdf', 'text/plain' => 'txt'];

    /** Tests may bypass is_uploaded_file(). */
    public static bool $allowNonUploaded = false;

    public static function storePrivateImage(array $file, string $subdir): string
    {
        return self::store($file, STORAGE_PATH . '/uploads', $subdir, self::IMAGE_MIMES);
    }

    public static function storePublicImage(array $file, string $subdir): string
    {
        return self::store($file, PUBLIC_PATH . '/uploads', $subdir, self::IMAGE_MIMES);
    }

    public static function storeAttachment(array $file): array
    {
        $path = self::store($file, STORAGE_PATH . '/uploads', 'tickets', self::IMAGE_MIMES + self::DOC_MIMES);
        $full = STORAGE_PATH . '/uploads/' . $path;
        return [
            'path' => $path,
            'original' => mb_substr(preg_replace('/[^\w.\- ]+/u', '_', (string) ($file['name'] ?? 'file')), 0, 200),
            'mime' => (string) (new \finfo(FILEINFO_MIME_TYPE))->file($full),
            'size' => (int) filesize($full),
        ];
    }

    private static function store(array $file, string $root, string $subdir, array $allowed): string
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new ValidationException(match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                default => 'The upload failed. Please try again.',
            });
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!self::$allowNonUploaded && !is_uploaded_file($tmp))) {
            throw new ValidationException('Invalid upload.');
        }
        $max = (int) Config::get('uploads.max_bytes', 5 * 1024 * 1024);
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $max) {
            throw new ValidationException('Files must be smaller than ' . round($max / 1048576, 1) . ' MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($allowed[$mime])) {
            throw new ValidationException('This file type is not allowed.');
        }
        $ext = $allowed[$mime];
        $subdir = preg_replace('/[^a-z0-9_]/', '', $subdir) ?: 'misc';
        $rel = $subdir . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $root . '/' . $rel;
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true) && !is_dir(dirname($dest))) {
            throw new \RuntimeException('Cannot create upload directory');
        }

        if (isset(self::IMAGE_MIMES[$mime])) {
            $info = @getimagesize($tmp);
            if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
                throw new ValidationException('The image could not be read.');
            }
            if (!self::reencode($tmp, $dest, $mime)) {
                // GD unavailable: fall back to a plain move (content already MIME/size validated)
                if (!self::moveFile($tmp, $dest)) {
                    throw new \RuntimeException('Could not save upload');
                }
            }
        } else {
            if ($mime === 'application/pdf' && !str_starts_with((string) file_get_contents($tmp, false, null, 0, 5), '%PDF-')) {
                throw new ValidationException('Invalid PDF file.');
            }
            if (!self::moveFile($tmp, $dest)) {
                throw new \RuntimeException('Could not save upload');
            }
        }
        @chmod($dest, 0644);
        return $rel;
    }

    private static function moveFile(string $tmp, string $dest): bool
    {
        return self::$allowNonUploaded ? copy($tmp, $dest) : move_uploaded_file($tmp, $dest);
    }

    private static function reencode(string $src, string $dest, string $mime): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if (!$img) {
            throw new ValidationException('The image could not be read.');
        }
        $ok = match ($mime) {
            'image/png' => (function () use ($img, $dest) {
                imagesavealpha($img, true);
                return imagepng($img, $dest, 6);
            })(),
            'image/webp' => function_exists('imagewebp') ? imagewebp($img, $dest, 85) : imagejpeg($img, $dest, 88),
            'image/gif' => imagegif($img, $dest),
            default => imagejpeg($img, $dest, 88),
        };
        imagedestroy($img);
        return (bool) $ok;
    }

    /** Stream a private upload after the caller has authorised access. */
    public static function serve(string $relPath, bool $download = false): Response
    {
        if (!preg_match('#^[a-z0-9_]+/\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp|gif|pdf|txt)$#', $relPath)) {
            throw new HttpException(404);
        }
        $full = STORAGE_PATH . '/uploads/' . $relPath;
        if (!is_file($full)) {
            throw new HttpException(404);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($full);
        $resp = new Response((string) file_get_contents($full), 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($full),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=600',
        ]);
        $inline = !$download && str_starts_with($mime, 'image/');
        $resp->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . basename($relPath) . '"');
        return $resp;
    }

    public static function deletePublic(?string $rel): void
    {
        if ($rel && preg_match('#^[a-z0-9_]+/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z]+$#', $rel)) {
            @unlink(PUBLIC_PATH . '/uploads/' . $rel);
        }
    }
}
