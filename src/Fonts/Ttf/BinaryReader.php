<?php

declare(strict_types=1);

namespace Pagyra\Fonts\Ttf;

final readonly class BinaryReader
{
    public function __construct(private string $data)
    {
    }

    public function length(): int
    {
        return strlen($this->data);
    }

    public function u8(int $offset): int
    {
        $this->guard($offset, 1);
        return ord($this->data[$offset]);
    }

    public function u16(int $offset): int
    {
        $this->guard($offset, 2);
        return unpack('n', substr($this->data, $offset, 2))[1];
    }

    public function i16(int $offset): int
    {
        $value = $this->u16($offset);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function u32(int $offset): int
    {
        $this->guard($offset, 4);
        return unpack('N', substr($this->data, $offset, 4))[1];
    }

    public function bytes(int $offset, int $length): string
    {
        $this->guard($offset, $length);
        return substr($this->data, $offset, $length);
    }

    public function tag(int $offset): string
    {
        return $this->bytes($offset, 4);
    }

    private function guard(int $offset, int $length): void
    {
        if ($offset < 0 || $length < 0 || $offset + $length > strlen($this->data)) {
            throw new \OutOfBoundsException('Read beyond font buffer bounds');
        }
    }
}
