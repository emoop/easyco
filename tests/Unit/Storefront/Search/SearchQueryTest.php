<?php

namespace Tests\Unit\Storefront\Search;

use App\Storefront\Search\SearchQuery;
use PHPUnit\Framework\TestCase;

/** SRCH-1 A: the normalisation of what a visitor typed. */
class SearchQueryTest extends TestCase
{
    public function test_it_trims_and_collapses_whitespace_and_splits_into_words(): void
    {
        $q = SearchQuery::fromInput("   червена \t  рокля \n  ");

        $this->assertSame('червена рокля', $q->text);
        $this->assertSame(['червена', 'рокля'], $q->words);
        $this->assertTrue($q->searchable());
    }

    public function test_one_character_is_not_a_search_and_nothing_is_empty(): void
    {
        $this->assertFalse(SearchQuery::fromInput('a')->searchable());
        $this->assertFalse(SearchQuery::fromInput(' я ')->searchable());
        $this->assertTrue(SearchQuery::fromInput('ab')->searchable());
        $this->assertTrue(SearchQuery::fromInput('   ')->isEmpty());
        $this->assertTrue(SearchQuery::fromInput(null)->isEmpty());
        $this->assertTrue(SearchQuery::fromInput(['x'])->isEmpty(), 'an array is not a query');
    }

    public function test_it_is_cut_at_100_characters_and_6_words(): void
    {
        $this->assertSame(100, mb_strlen(SearchQuery::fromInput(str_repeat('я', 250))->text));

        $q = SearchQuery::fromInput('a1 b2 c3 d4 e5 f6 g7 h8');
        $this->assertSame(['a1', 'b2', 'c3', 'd4', 'e5', 'f6'], $q->words);
    }

    public function test_like_metacharacters_are_escaped(): void
    {
        $q = SearchQuery::fromInput('100% a_b c\\d');

        $this->assertSame(['100\\%', 'a\\_b', 'c\\\\d'], $q->likeWords());
        $this->assertSame('100% a_b c\\d', $q->text, 'the text itself is untouched: it is printed back (escaped by the view)');
    }

    public function test_control_characters_and_broken_utf8_do_not_get_through(): void
    {
        $this->assertSame('ab cd', SearchQuery::fromInput("ab\x00\x07cd")->text);
        $this->assertTrue(mb_check_encoding(SearchQuery::fromInput("ab\xFF\xFEcd")->text, 'UTF-8'));
    }
}
