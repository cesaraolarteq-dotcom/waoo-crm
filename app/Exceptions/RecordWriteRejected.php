<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

final class RecordWriteRejected extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function when(bool $condition, int $status, string $message): void
    {
        if ($condition) {
            throw new self($status, $message);
        }
    }

    public static function unless(bool $condition, int $status, string $message): void
    {
        self::when(! $condition, $status, $message);
    }

    public function report(): bool
    {
        return true;
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], $this->status);
        }

        return response($this->getMessage(), $this->status);
    }
}
