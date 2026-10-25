<div id="addDoujinModal"
     role="dialog" aria-modal="true" aria-labelledby="addDoujinHeading"
     class="app-modal bg-black bg-opacity-50 hidden z-50">
    <div class="relative bg-white px-6 pt-6 pb-4 text-left shadow-2xl w-[800px] max-w-[95vw] rounded-lg">
        <div class="flex justify-between items-center pb-4 border-b border-gray-200">
            <h3 id="addDoujinHeading" class="text-lg font-bold text-gray-800">Add Doujins</h3>
            <button id="closeAddDoujinModal" type="button" class="text-gray-500 hover:text-gray-900 text-2xl" aria-label="Close Add Doujins">&times;</button>
        </div>

        <form id="addDoujinForm" method="POST" action="{{ route('doujin.upload.complete') }}"
              data-chunk-url="{{ route('doujin.upload.chunk') }}"
              data-complete-url="{{ route('doujin.upload.complete') }}"
              class="flex flex-col gap-3 pt-4">
            @csrf
            <div id="addDoujinInlineError" role="alert" class="app-alert app-alert-error {{ session('doujin_upload_error') ? '' : 'hidden' }}">{{ session('doujin_upload_error') }}</div>

            <div>
                <label for="doujinFolderInput" class="block mb-2 text-red-600 font-medium">Doujin Folder</label>
                <input id="doujinFolderInput" type="file" webkitdirectory directory multiple
                       class="block w-full rounded-md border border-gray-200 bg-gray-100 p-3 text-sm font-normal text-gray-800 file:mr-4 file:rounded file:border-0 file:bg-emerald-700 file:px-4 file:py-2 file:font-medium file:text-white file:cursor-pointer" />
            </div>

            <div id="doujinImportPreview" class="hidden">
                <p id="doujinFolderSummary" class="mb-3 text-sm font-medium text-gray-800 normal-case"></p>
                <div class="max-h-[300px] overflow-y-auto rounded-md border border-gray-200">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-100 text-gray-700"><tr><th class="px-3 py-2">Title</th><th class="px-3 py-2">Author</th><th class="px-3 py-2">Status</th></tr></thead>
                        <tbody id="doujinImportFiles" class="divide-y divide-gray-200 text-gray-800 normal-case"></tbody>
                    </table>
                </div>
            </div>

            <div id="doujinImportProgress" class="hidden" aria-live="polite">
                <p id="doujinImportStatus" class="text-sm text-gray-700 normal-case"></p>
                <progress id="doujinImportProgressBar" max="100" value="0" class="mt-2 h-2 w-full accent-emerald-700"></progress>
            </div>

            <div class="flex justify-end gap-3 border-t border-gray-200 pt-3">
                <button id="cancelAddDoujinModal" type="button" class="px-5 py-3 rounded border border-gray-200 text-gray-700 font-medium hover:bg-gray-100">Cancel</button>
                <button id="submitAddDoujinBtn" type="submit" disabled class="flatGreen text-white px-5 py-3 rounded disabled:opacity-60 disabled:cursor-not-allowed">Add Doujins</button>
            </div>
        </form>
    </div>
</div>
