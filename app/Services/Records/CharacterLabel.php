<?php
namespace App\Services\Records;

final class CharacterLabel
{
    public static function fromSnapshot(array $character): string
    {
        return $character['name'].(isset($character['outfit']) ? ' ('.$character['outfit'].')' : '');
    }
}
