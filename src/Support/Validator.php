<?php

declare(strict_types=1);

namespace Api\Support;

use Api\Http\HttpException;

final class Validator
{
    /**
     * Valida $data con reglas separadas por "|": required, string, integer, email, isbn, min:N, max:N.
     * Para strings min/max miden la longitud; para enteros, el valor.
     * Devuelve solo los campos presentes y ya normalizados (strings recortados, enteros casteados).
     *
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @return array<string,mixed>
     * @throws HttpException 422 con los errores por campo
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $clean = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                if (in_array('required', $ruleList, true)) {
                    $errors[$field][] = 'El campo es obligatorio.';
                }
                continue;
            }

            if (in_array('integer', $ruleList, true)) {
                if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
                    $errors[$field][] = 'Debe ser un número entero.';
                    continue;
                }
                $value = (int) $value;
            } elseif (in_array('string', $ruleList, true) || in_array('email', $ruleList, true) || in_array('isbn', $ruleList, true)) {
                if (!is_string($value)) {
                    $errors[$field][] = 'Debe ser texto.';
                    continue;
                }
            } else {
                $errors[$field][] = 'Tipo de dato no soportado.';
                continue;
            }

            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $size = is_int($value) ? $value : mb_strlen($value);

                switch ($name) {
                    case 'min':
                        if ($size < (int) $arg) {
                            $errors[$field][] = is_int($value)
                                ? "Debe ser mayor o igual a $arg."
                                : "Debe tener al menos $arg caracteres.";
                        }
                        break;
                    case 'max':
                        if ($size > (int) $arg) {
                            $errors[$field][] = is_int($value)
                                ? "Debe ser menor o igual a $arg."
                                : "Debe tener como máximo $arg caracteres.";
                        }
                        break;
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = 'Debe ser un correo electrónico válido.';
                        }
                        break;
                    case 'isbn':
                        $digits = str_replace('-', '', $value);
                        if (!preg_match('/^(\d{9}[\dXx]|\d{13})$/', $digits)) {
                            $errors[$field][] = 'Debe ser un ISBN-10 o ISBN-13 válido.';
                        }
                        break;
                }
            }

            $clean[$field] = $value;
        }

        if ($errors) {
            throw new HttpException(422, 'validation_error', 'Los datos enviados no son válidos.', $errors);
        }

        return $clean;
    }

    /** Convierte un segmento de la URL en un id entero positivo o responde 404. */
    public static function id(string $raw): int
    {
        if (!ctype_digit($raw) || (int) $raw < 1) {
            throw new HttpException(404, 'not_found', 'El recurso solicitado no existe.');
        }

        return (int) $raw;
    }
}
