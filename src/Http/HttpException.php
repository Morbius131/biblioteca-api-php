<?php

declare(strict_types=1);

namespace Api\Http;

use RuntimeException;

/** Error controlado que se convierte en una respuesta JSON con el estado HTTP indicado. */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string,mixed> $details Información extra (p. ej. errores por campo).
     * @param array<string,string> $headers Cabeceras adicionales (p. ej. Allow, WWW-Authenticate).
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public function toResponse(): Response
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->details) {
            $error['details'] = $this->details;
        }

        return Response::json(['error' => $error], $this->status, $this->headers);
    }
}
