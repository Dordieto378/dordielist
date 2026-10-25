<?php

namespace Tests\Unit;

use App\Support\DoujinImportMetadata;
use PHPUnit\Framework\TestCase;

class DoujinImportMetadataTest extends TestCase
{
    public function test_the_entire_folder_name_is_used_as_one_author(): void
    {
        $metadata = DoujinImportMetadata::fromRelativePath('Collection/Anderson AND Candy and anderson/Shared Book.ZIP');

        $this->assertSame('Shared Book', $metadata['title']);
        $this->assertSame('Anderson AND Candy and anderson', $metadata['author']);
        $this->assertSame('Shared Book.ZIP', $metadata['filename']);
    }
}
