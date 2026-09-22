<?php

declare(strict_types=1);

namespace App\Services;

/** Artefato XML Philips imutável em bytes ISO-8859-1. */
final class PhilipsSubmissionDocument
{
    public function __construct(
        public readonly string $filename,
        public readonly string $content,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $pdfFilename,
        public readonly string $taskFilePath,
        public readonly bool $documentTypeApplicable,
        public readonly bool $deleteFile,
        public readonly ?string $documentType,
        public readonly bool $patientNameComponentsOmitted = false,
        public readonly bool $patientNameAsFamily = false
    ) {
        if ($filename === '' || !str_ends_with(strtolower($filename), '.xml')) {
            throw new \InvalidArgumentException('Nome de XML inválido.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,180}\.xml$/', $filename)) {
            throw new \InvalidArgumentException('Nome de XML inválido.');
        }
        if ($content === '' || $size !== strlen($content) || !hash_equals(hash('sha256', $content), $sha256)) {
            throw new \InvalidArgumentException('Integridade do XML inválida.');
        }
        if (!preg_match('/^VOXEL_[A-Za-z0-9._-]{1,160}\.pdf$/', $pdfFilename)) {
            throw new \InvalidArgumentException('Nome de PDF inválido.');
        }
        if ($taskFilePath === '') {
            throw new \InvalidArgumentException('Caminho lógico Philips inválido.');
        }
    }

    /** @return array{type:string,filename:string,content:string,sha256:string,size:int,pdf_filename:string} */
    public function asArtifact(): array
    {
        return [
            'type' => 'xml',
            'filename' => $this->filename,
            'content' => $this->content,
            'sha256' => $this->sha256,
            'size' => $this->size,
            'pdf_filename' => $this->pdfFilename,
        ];
    }
}
