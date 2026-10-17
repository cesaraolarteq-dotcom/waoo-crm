<?php
namespace App\Services\Imports;

use Illuminate\Http\UploadedFile;

// Bounded, strict CSV reader. No spreadsheet execution and no permissive recovery from malformed quotes.
final class ImportCsv
{
    public const BYTES=5242880;
    public const ROWS=['projects'=>2000,'customers'=>5000,'payments'=>10000];
    public function upload(UploadedFile $file,string $role,array $profile):array
    {
        if(!$file->isValid()||!in_array(strtolower($file->getClientOriginalExtension()),['csv','tsv','txt'],true))ImportInput::fail('files.'.$role,'csv_required');
        $stream=@fopen($file->getRealPath(),'rb');if(!$stream)ImportInput::fail('files.'.$role,'unreadable_file');
        try{$bytes=stream_get_contents($stream,self::BYTES+1);}finally{fclose($stream);}
        if($bytes===false||strlen($bytes)>self::BYTES)ImportInput::fail('files.'.$role,'file_size_limit');
        return ['bytes'=>$bytes,'sha256'=>hash('sha256',$bytes),'byte_count'=>strlen($bytes),'name'=>mb_substr(basename(str_replace('\\','/',$file->getClientOriginalName())),0,255),
            ...$this->parse($bytes,$role,$profile)];
    }
    public function parse(#[\SensitiveParameter] string $bytes,string $role,array $profile):array
    {
        if(!isset(self::ROWS[$role])||strlen($bytes)>self::BYTES)ImportInput::fail('files','file_size_or_role');
        $field='files.'.$role;
        if(str_contains($bytes,"\0")||preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/',$bytes)||str_starts_with($bytes,'%PDF-'))ImportInput::fail($field,'binary_not_csv');
        if($profile['encoding']==='windows-1252'){$bytes=@iconv('Windows-1252','UTF-8',$bytes);if($bytes===false)ImportInput::fail($field,'invalid_encoding');}
        if(!mb_check_encoding($bytes,'UTF-8'))ImportInput::fail($field,'invalid_encoding');
        if(str_starts_with($bytes,"\xEF\xBB\xBF"))$bytes=substr($bytes,3);
        $rows=[];$recordStarted=false;$headers=null;$cells=[];$cell='';$state='start';$line=1;$startLine=1;$n=strlen($bytes);$delimiter=$profile['delimiter'];
        $finishCell=function()use(&$cells,&$cell,&$state,$field){$cells[]=$cell;if(count($cells)>100)ImportInput::fail($field,'column_limit');$cell='';$state='start';};
        $finishRow=function()use(&$rows,&$headers,&$cells,&$startLine,&$recordStarted,$role,$field){
            if($cells===['']&&!$recordStarted){$cells=[];return;}
            $recordStarted=false;
            if($headers===null){$headers=$cells;if($headers===[]||count(array_filter($headers,fn($s)=>trim($s)!==''))===0)ImportInput::fail($field,'missing_headers');}
            else{if(count($cells)!==count($headers))ImportInput::fail($field.'.'.$startLine,'column_count');$rows[]=['line'=>$startLine,'cells'=>$cells];if(count($rows)>self::ROWS[$role])ImportInput::fail($field,'row_limit');}
            $cells=[];
        };
        for($i=0;$i<$n;$i++){
            $c=$bytes[$i];if($c!=="\n"&&$c!=="\r")$recordStarted=true;
            if($state==='quoted'){
                if($c==='"'){if($i+1<$n&&$bytes[$i+1]==='"'){$cell.='"';$i++;}else{$state='closed';}}
                else{$cell.=$c;if($c==="\n")$line++;elseif($c==="\r"&&($bytes[$i+1]??'')!=="\n")$line++;}
            }elseif($c===$delimiter){$finishCell();}
            elseif($c==="\n"||$c==="\r"){
                $finishCell();$finishRow();if($c==="\r"&&($bytes[$i+1]??'')==="\n")$i++;$line++;$startLine=$line;
            }elseif($state==='closed'){ImportInput::fail($field.'.'.$startLine,'characters_after_quote');}
            elseif($c==='"'){if($state!=='start')ImportInput::fail($field.'.'.$startLine,'unexpected_quote');$state='quoted';}
            else{$cell.=$c;$state='unquoted';}
            if(strlen($cell)>32768)ImportInput::fail($field.'.'.$startLine,'cell_limit');
        }
        if($state==='quoted')ImportInput::fail($field.'.'.$startLine,'unclosed_quote');
        if($cell!==''||$cells!==[]||$state!=='start'){$finishCell();$finishRow();}
        if($headers===null)ImportInput::fail($field,'missing_headers');
        if(isset($profile['source_format'])&&($role!=='projects'||$headers!==HoneyBookFutureSource::HEADERS))ImportInput::fail('files','exact_24_future_headers_required');
        foreach($profile['columns'][$role]??[] as $index)if($index>=count($headers))ImportInput::fail('profile.columns.'.$role,'column_out_of_range');
        return ['headers'=>$headers,'rows'=>$rows];
    }
}
