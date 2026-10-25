import assert from 'node:assert/strict';
import test from 'node:test';
import { matchesArtistSearch } from '../../resources/js/doujin-artists.js';

test('artist search matches partial names regardless of case or accents', () => {
    assert.equal(matchesArtistSearch('Studio Andre', 'ANDR'), true);
    assert.equal(matchesArtistSearch('Andre\u0301 Studio', 'andre'), true);
    assert.equal(matchesArtistSearch('Studio Andre', 'unrelated'), false);
});

test('artist search handles multiple words and extra whitespace', () => {
    assert.equal(matchesArtistSearch('Example Artist Studio', '  studio  example '), true);
    assert.equal(matchesArtistSearch('Example Artist Studio', 'example missing'), false);
    assert.equal(matchesArtistSearch('Example Artist', '   '), true);
});

test('artist search supports non-Latin names and literal punctuation', () => {
    assert.equal(matchesArtistSearch('山田太郎', '山田'), true);
    assert.equal(matchesArtistSearch('[Studio] Artist', '[studio]'), true);
    assert.equal(matchesArtistSearch('Another Artist', '[studio]'), false);
});
