<?php
declare(strict_types=1);

namespace HelpdeskForm\Services;

use Psr\Http\Message\UploadedFileInterface;

class FileUploadService
{
    private string $uploadPath;
    private int $maxFileSize;
    private array $allowedTypes;
    
    public function __construct(string $uploadPath, int $maxFileSize, array $allowedTypes)
    {
        $this->uploadPath = rtrim($uploadPath, '/');
        $this->maxFileSize = $maxFileSize;
        $this->allowedTypes = $allowedTypes;
        
        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }
    }
    
    public function uploadFile(UploadedFileInterface $file, string $submissionUuid): array
    {
        // Validate file
        $this->validateFile($file);
        
        // Generate unique filename
        $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $filename = $submissionUuid . '_' . uniqid() . '.' . strtolower($extension);
        $filepath = $this->uploadPath . '/' . $filename;
        
        // Move uploaded file
        $file->moveTo($filepath);
        
        // Get file info
        $fileInfo = [
            'original_filename' => $file->getClientFilename(),
            'stored_filename' => $filename,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getClientMediaType(),
            'file_path' => $filepath
        ];
        
        return $fileInfo;
    }
    
    public function uploadMultipleFiles(array $files, string $submissionUuid): array
    {
        $uploadedFiles = [];
        
        foreach ($files as $file) {
            if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
                $uploadedFiles[] = $this->uploadFile($file, $submissionUuid);
            }
        }
        
        return $uploadedFiles;
    }
    
    private function validateFile(UploadedFileInterface $file): void
    {
        // Check for upload errors
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload error: ' . $this->getUploadErrorMessage($file->getError()));
        }
        
        // Check file size
        if ($file->getSize() > $this->maxFileSize) {
            $maxSizeMB = round($this->maxFileSize / 1024 / 1024, 2);
            throw new \RuntimeException("File size exceeds maximum allowed size of {$maxSizeMB}MB");
        }
        
        // Check file type
        $extension = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedTypes)) {
            $allowedTypes = implode(', ', $this->allowedTypes);
            throw new \RuntimeException("File type not allowed. Allowed types: {$allowedTypes}");
        }
        
        // Additional security checks
        $this->validateFileContent($file);
    }
    
    private function validateFileContent(UploadedFileInterface $file): void
    {
        // Get file stream for validation without moving the file
        $stream = $file->getStream();
        $stream->rewind();
        $content = $stream->getContents();
        $stream->rewind();
        
        // Check if file is actually an image for image extensions
        $extension = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        
        if (in_array($extension, $imageExtensions)) {
            // Write content to temp file for image validation
            $tempFile = tempnam(sys_get_temp_dir(), 'upload_validation');
            file_put_contents($tempFile, $content);
            
            $imageInfo = getimagesize($tempFile);
            unlink($tempFile);
            
            if ($imageInfo === false) {
                throw new \RuntimeException('Invalid image file');
            }
        }
        
        // Check for PHP code in files (basic security)
        if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
            throw new \RuntimeException('File contains potentially dangerous content');
        }
    }
    
    private function getUploadErrorMessage(int $error): string
    {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'File is too large';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }
    
    public function getFile(string $filename): ?string
    {
        $filepath = $this->uploadPath . '/' . $filename;
        
        if (!file_exists($filepath) || !is_file($filepath)) {
            return null;
        }
        
        // Security check - ensure file is within upload directory
        $realUploadPath = realpath($this->uploadPath);
        $realFilePath = realpath($filepath);
        
        if (!$realFilePath || strpos($realFilePath, $realUploadPath) !== 0) {
            return null;
        }
        
        return $filepath;
    }
    
    public function deleteFile(string $filename): bool
    {
        $filepath = $this->getFile($filename);
        
        if ($filepath && file_exists($filepath)) {
            return unlink($filepath);
        }
        
        return false;
    }
    
    public function getFileInfo(string $filename): ?array
    {
        $filepath = $this->getFile($filename);
        
        if (!$filepath) {
            return null;
        }
        
        return [
            'filename' => $filename,
            'size' => filesize($filepath),
            'mime_type' => mime_content_type($filepath),
            'modified' => filemtime($filepath)
        ];
    }
    
    public function cleanupOldFiles(int $olderThanDays = 30): int
    {
        $deleted = 0;
        $cutoff = time() - ($olderThanDays * 24 * 60 * 60);
        
        $files = glob($this->uploadPath . '/*');
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
}
