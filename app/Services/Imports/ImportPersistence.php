<?php
namespace App\Services\Imports;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
final class ImportPersistence
{
    public static function transaction(callable $work,int $attempts=3):mixed
    {
        try{return DB::transaction($work,$attempts);}
        catch(QueryException){
            // SQL/bindings may contain imported client cells. Discard the original exception chain.
            throw new \RuntimeException('Private import persistence unavailable.');
        }
    }
}
