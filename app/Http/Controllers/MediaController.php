<?php
// app/Http/Controllers/MediaController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Doujin;
use App\Models\DoujinPage;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function index(Request $request)
    {
        $doujins = Doujin::with('pages')->get();

        $media = $doujins;

        return view('media.doujins', [
            'media' => $media,
        ]);
    }

    /**
     * @param  Doujin  $doujin   
     * @param  int     $page     
     */
    public function readPage(Doujin $doujin, $page)
    {
        $pageNumber = intval($page);
        $doujinPage = $doujin->pages()
                             ->where('page_number', $pageNumber)
                             ->firstOrFail();

        $pageUrl = Storage::disk('b2')->url($doujinPage->file_path);

        $prevPage = $doujin->pages()->where('page_number', '<', $pageNumber)
                                   ->orderBy('page_number', 'desc')
                                   ->value('page_number'); 
        $nextPage = $doujin->pages()->where('page_number', '>', $pageNumber)
                                   ->orderBy('page_number', 'asc')
                                   ->value('page_number'); 

        return view('pages.read', [
            'doujin'     => $doujin,
            'pageNumber' => $pageNumber,
            'pageUrl'    => $pageUrl,
            'prevPage'   => $prevPage,
            'nextPage'   => $nextPage,
        ]);
    }
}
