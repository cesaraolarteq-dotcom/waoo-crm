<?php
namespace App\Services\MigrationVerification;

/** Independent raw-response decoder. No import profile, CSV mapping or import normalizer. */
final class RawHoneyBookSnapshot
{
    private array $records=[];
    private array $issues=[];
    private array $pagination=[];
    private int $recognized=0;
    private array $totals=[];
    private const COLLECTIONS=['projects'=>'projects','contacts'=>'contacts','workspaces'=>'workspaces',
        'smartFiles'=>'files','smart_files'=>'files','files'=>'files','responses'=>'responses'];
    private const CALLS=['listProjects'=>'projects','listContacts'=>'contacts','listWorkspaces'=>'workspaces',
        'listWorkspaceSmartFiles'=>'files','getSmartFileResponses'=>'responses'];

    public function read(string $directory): array
    {
        $this->records=array_fill_keys(['projects','contacts','workspaces','files','responses'],[]);
        $this->issues=[];$this->pagination=[];$this->recognized=0;$this->totals=[];$hashes=[];$documents=[];
        foreach(['projects.json'=>'projects','contacts-pipeline-files.json'=>'contacts','workspaces.json'=>'workspaces','file-responses.json'=>'responses'] as $file=>$kind){
            $bytes=$this->bytes($directory,$file);$hashes[$file]=hash('sha256',$bytes);
            $value=json_decode($bytes,true,128,JSON_THROW_ON_ERROR);
            if(!is_array($value))throw new \RuntimeException('invalid_snapshot');
            $documents[$file]=$value;
        }
        $flat=(new FlatHoneyBookCapture)->decode($documents);
        if($flat===null)foreach(['projects.json'=>'projects','contacts-pipeline-files.json'=>'contacts','workspaces.json'=>'workspaces','file-responses.json'=>'responses'] as $file=>$kind){
            $value=$documents[$file];
            $before=$this->recognized;
            $this->walk($value,$kind,$file,'');
            if($this->recognized===$before)$this->issues[]=['file'=>$file,'code'=>'empty_or_unsupported_response'];
        }
        foreach($this->totals as $kind=>$totals)if(count(array_unique($totals))!==1||$totals[0]!==count($this->records[$kind]))$this->issues[]=['kind'=>$kind,'code'=>'declared_total_not_reconciled'];
        if($flat===null)foreach(['projects','contacts','workspaces','files'] as $kind){
            $pages=$this->pagination[$kind]??[];
            if(!$pages||end($pages)!==false)$this->issues[]=['kind'=>$kind,'code'=>'pagination_completeness_unverified'];
        }
        $optional=[];
        foreach(['exclusions.json','originals.json'] as $file){
            if(is_file($directory.'/'.$file)){
                $bytes=$this->bytes($directory,$file);$hashes[$file]=hash('sha256',$bytes);
                $optional[$file]=json_decode($bytes,true,128,JSON_THROW_ON_ERROR);
                if(!is_array($optional[$file])||!array_is_list($optional[$file]))throw new \RuntimeException('invalid_manifest');
            }
        }
        return ['records'=>$flat['records']??$this->records,'issues'=>$flat['issues']??$this->issues,
            'capture_shape'=>$flat['capture_shape']??'envelopes-v1','project_relations'=>$flat['project_relations']??[], 'snapshot_sha256'=>$hashes,
            'exclusions'=>$optional['exclusions.json']??[],'originals'=>$optional['originals.json']??null];
    }
    public function bytes(string $directory,string $relative): string
    {
        if(str_contains($relative,'://')||str_contains($relative,"\0")||str_starts_with($relative,'/')||in_array('..',explode('/',$relative),true))throw new \RuntimeException('unsafe_snapshot_path');
        $root=realpath($directory);$path=realpath($directory.'/'.$relative);
        if(!$root||!$path||!str_starts_with($path,$root.'/')||is_link($directory.'/'.$relative)||!is_file($path)||filesize($path)>33554432)throw new \RuntimeException('unsafe_snapshot_file');
        $bytes=file_get_contents($path,false,null,0,33554433);if($bytes===false||strlen($bytes)>33554432)throw new \RuntimeException('unreadable_snapshot');return $bytes;
    }
    private function walk(array $node,string $kind,string $file,string $path,int $depth=0): void
    {
        if($depth>30)throw new \RuntimeException('snapshot_depth');
        if(($node['isError']??false)===true||($node['success']??true)===false){$this->issues[]=['file'=>$file,'path'=>$path,'code'=>'source_tool_error'];return;}
        foreach(['total_count','totalCount','total_records'] as $key)if(isset($node[$key])&&is_int($node[$key]))$this->totals[$kind][]=$node[$key];
        foreach(['next_cursor','nextCursor','next_page','nextPage'] as $key)if(array_key_exists($key,$node))$this->pagination[$kind][]=!in_array($node[$key],[null,'',false],true);
        foreach(['has_more','hasMore'] as $key)if(array_key_exists($key,$node))$this->pagination[$kind][]=$node[$key]===false?false:true;
        if(isset($node['pagination'])&&is_array($node['pagination'])){
            foreach(['has_more','hasMore'] as $key)if(array_key_exists($key,$node['pagination']))$this->pagination[$kind][]=$node['pagination'][$key]===false?false:true;
        }
        if(isset($node['pagination'])&&is_array($node['pagination'])){
            $p=$node['pagination'];
            foreach(['total_count','totalCount','total_records'] as $key)if(array_key_exists($key,$p)){
                if(is_int($p[$key]))$this->totals[$kind][]=$p[$key];else $this->issues[]=['file'=>$file,'path'=>$path.'/pagination/'.$key,'code'=>'unsupported_total_count'];
            }
            foreach(['next_cursor','nextCursor','next_page','nextPage'] as $key)if(array_key_exists($key,$p))$this->pagination[$kind][]=!in_array($p[$key],[null,'',false],true);
            foreach($p as $key=>$value)if(!in_array($key,['total_count','totalCount','total_records','has_more','hasMore','next_cursor','nextCursor','next_page','nextPage'],true))$this->issues[]=['file'=>$file,'path'=>$path.'/pagination/'.$key,'code'=>'unverified_pagination_metadata','raw_value'=>$value];
        }
        foreach(['next_cursor','nextCursor','next_page','nextPage'] as $key)if(!in_array($node[$key]??null,[null,'',false],true)&&(($node['has_more']??null)===false||($node['hasMore']??null)===false))$this->issues[]=['file'=>$file,'path'=>$path,'code'=>'pagination_metadata_conflict'];
        if(array_is_list($node)){
            foreach($node as $i=>$value)if(is_array($value))$this->walk($value,$kind,$file,$path.'/'.$i,$depth+1);
            return;
        }
        if(isset($node['structuredContent'])&&is_array($node['structuredContent'])){$this->walk($node['structuredContent'],$kind,$file,$path.'/structuredContent',$depth+1);return;}
        if(isset($node['content'])&&is_array($node['content'])){
            foreach($node['content'] as $i=>$block)if(($block['type']??null)==='text'){
                try{$value=json_decode($block['text']??'',true,128,JSON_THROW_ON_ERROR);}catch(\JsonException){$this->issues[]=['file'=>$file,'path'=>$path.'/content/'.$i,'code'=>'unsupported_tool_text_response','raw_text'=>$block['text']??''];continue;}
                if(is_array($value))$this->walk($value,$kind,$file,$path.'/content/'.$i.'/text',$depth+1);
            }
            return;
        }
        $hasId=isset($node['id'])||isset($node['_id'])||isset($node['project_id'])||isset($node['file_id'])||isset($node['workspace_id'])||isset($node['projectId'])||isset($node['contact_id'])||isset($node['contactId'])||isset($node['fileId']);
        $nestedRecord=$hasId&&count(array_intersect(array_keys($node),array_keys(self::COLLECTIONS)))>0;
        if($nestedRecord)$this->records[$kind][]=['file'=>$file,'path'=>$path,'value'=>$node];
        $collection=false;
        foreach(self::COLLECTIONS as $key=>$target)if(isset($node[$key])&&is_array($node[$key])){
            $this->recognized++;$collection=true;$this->walk($node[$key],$target,$file,$path.'/'.$key,$depth+1);
            if($target!==$kind&&isset($node['has_more']))$this->pagination[$target][]=$node['has_more']!==false;
        }
        foreach(self::CALLS as $key=>$target)if(isset($node[$key])&&is_array($node[$key])){
            $this->recognized++;$collection=true;$this->walk($node[$key],$target,$file,$path.'/'.$key,$depth+1);
        }
        if($collection){
            if(!$nestedRecord)foreach($node as $key=>$value)if(!isset(self::COLLECTIONS[$key])&&!isset(self::CALLS[$key])&&!in_array($key,['has_more','hasMore','pagination','total_count','totalCount','total_records','next_cursor','nextCursor','next_page','nextPage'],true))$this->issues[]=['file'=>$file,'path'=>$path.'/'.$key,'code'=>'unverified_response_metadata','raw_value'=>$value];
            return;
        }
        if(isset($node['tool'],$node['result'])&&isset(self::CALLS[$node['tool']])&&is_array($node['result'])){
            $this->walk($node['result'],self::CALLS[$node['tool']],$file,$path.'/result',$depth+1);return;
        }
        foreach(['data','result','response','pages','results'] as $wrapper)if(isset($node[$wrapper])&&is_array($node[$wrapper])){
            $this->walk($node[$wrapper],$kind,$file,$path.'/'.$wrapper,$depth+1);return;
        }
        if(isset($node['id'])||isset($node['_id'])||isset($node['project_id'])||isset($node['file_id'])||isset($node['workspace_id'])||isset($node['projectId'])||isset($node['contact_id'])||isset($node['contactId'])||isset($node['fileId'])){
            $this->recognized++;$this->records[$kind][]=['file'=>$file,'path'=>$path,'value'=>$node];return;
        }
        $this->issues[]=['file'=>$file,'path'=>$path,'code'=>'unsupported_response_shape','raw_node'=>$node];
    }
}
