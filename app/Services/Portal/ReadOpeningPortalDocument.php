<?php
namespace App\Services\Portal;
use App\Models\CutoverOriginalDocument;
use App\Services\Imports\{CutoverDocuments,ImportPersistence};
final class ReadOpeningPortalDocument
{
    public function read(PortalContext $client):string
    {
        return ImportPersistence::transaction(function()use($client){
            $a=OpeningPortalAccess::resolve($client);$id=$a['opening']['opening']['document_id'];
            return app(CutoverDocuments::class)->read(CutoverOriginalDocument::whereKey($id)->sharedLock()->firstOrFail());
        });
    }
}
