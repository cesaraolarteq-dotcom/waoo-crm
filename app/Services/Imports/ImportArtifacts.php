<?php
namespace App\Services\Imports;
use App\Models\ImportFile;
use Illuminate\Support\Facades\Storage;
final class ImportArtifacts
{
    private function path(string $path):void
    {
        if(!preg_match('#^honeybook/[a-f0-9]{32}/(?:projects|customers|payments)\.csv$#D',$path))throw new \RuntimeException('Private import artifact unavailable.');
    }
    public function put(string $path,#[\SensitiveParameter] string $bytes):void
    {
        $this->path($path);
        try{if(!Storage::disk('local')->put($path,$bytes,['visibility'=>'private']))throw new \RuntimeException();}
        catch(\Throwable){throw new \RuntimeException('Private import artifact unavailable.');}
    }
    public function read(ImportFile $file):string
    {
        $this->path($file->private_path);
        try{$stream=Storage::disk('local')->readStream($file->private_path);if(!is_resource($stream))throw new \RuntimeException();
            try{$bytes=stream_get_contents($stream,ImportCsv::BYTES+1);}finally{fclose($stream);}
            if($bytes===false||strlen($bytes)!==$file->byte_count||!hash_equals($file->sha256,hash('sha256',$bytes)))throw new \RuntimeException();return $bytes;
        }catch(\Throwable){throw new \RuntimeException('Private import artifact unavailable.');}
    }
    public function delete(string $path):bool
    {
        $this->path($path);
        try{return !Storage::disk('local')->exists($path)||Storage::disk('local')->delete($path);}catch(\Throwable){return false;}
    }
}
