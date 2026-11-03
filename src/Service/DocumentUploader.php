<?php

namespace App\Service;

use App\Entity\Document;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DocumentUploader
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/jpg',
    ];

    private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 Mo

    private string $uploadDirectory;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->uploadDirectory = $projectDir . '/var/uploads/documents';
        if (!is_dir($this->uploadDirectory)) {
            @mkdir($this->uploadDirectory, 0775, true);
        }
    }

    /**
     * Valide et téléverse un fichier, puis retourne l'entité Document persistable
     *
     * @throws \InvalidArgumentException si le type MIME ou la taille est invalide
     * @throws FileException si le déplacement échoue
     */
    public function upload(
        UploadedFile $file,
        string $type = 'DOCUMENT_INITIAL',
        ?User $uploader = null
    ): Document {
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
        $size = $file->getSize();
        $originalName = $file->getClientOriginalName();
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $isPdf = $ext === 'pdf' || $mimeType === 'application/pdf';
        $isImage = in_array($ext, ['png', 'jpg', 'jpeg'], true) || in_array($mimeType, ['image/png', 'image/jpeg'], true);

        if ($size > self::MAX_SIZE_BYTES) {
            throw new \InvalidArgumentException(sprintf('Le fichier dépasse la limite autorisée de 20 Mo (taille reçue : %.2f Mo).', $size / (1024 * 1024)));
        }

        if (!$isPdf && !$isImage) {
            throw new \InvalidArgumentException('Format de fichier non autorisé. Formats acceptés : PDF, PNG, JPEG.');
        }

        $extension = $file->guessExtension() ?: ($ext ?: 'bin');
        $storageName = sprintf('doc_%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(8)), $extension);

        $file->move($this->uploadDirectory, $storageName);

        $relativePath = 'var/uploads/documents/' . $storageName;

        $normalizedMimeType = $isPdf ? 'application/pdf' : ($mimeType ?: 'application/octet-stream');

        return new Document(
            $originalName,
            $storageName,
            $normalizedMimeType,
            $size,
            $relativePath,
            $type,
            $uploader
        );
    }

    public function getUploadDirectory(): string
    {
        return $this->uploadDirectory;
    }

    public function getAbsolutePath(Document $document): string
    {
        return $this->uploadDirectory . '/' . $document->getStorageName();
    }
}
