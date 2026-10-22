<?php
namespace App\Services\MigrationVerification;
final class PrivateMigrationAct
{
    public function write(string $path,array $act): void
    {
        $parent=realpath(dirname($path));
        if(!$parent||!str_starts_with($path,'/')||str_contains($path,'://')||is_link(dirname($path)))throw new \RuntimeException('private_report_path_required');
        foreach([base_path(),storage_path('logs'),public_path()] as $blocked){
            $real=realpath($blocked)?:$blocked;
            if($parent===$real||str_starts_with($parent,$real.'/'))throw new \RuntimeException('report_inside_repository_or_logs');
        }
        if((fileperms($parent)&0077)!==0)throw new \RuntimeException('report_directory_must_be_private');
        $old=umask(0077);
        try{$file=@fopen($path,'x+b');}finally{umask($old);}
        if(!$file)throw new \RuntimeException('report_exists_or_unwritable');
        try{
            if(!chmod($path,0600))throw new \RuntimeException('report_permissions');
            $json=json_encode($act,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
            $offset=0;while($offset<strlen($json)){
                $n=fwrite($file,substr($json,$offset));if(!$n)throw new \RuntimeException('report_write_failed');$offset+=$n;
            }
            fflush($file);
        }finally{fclose($file);}
    }
}
