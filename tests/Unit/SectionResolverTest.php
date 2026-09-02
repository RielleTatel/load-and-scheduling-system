<?php

namespace Tests\Unit;

use App\Services\Plantilla\SectionMatch;
use App\Services\Plantilla\SectionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): SectionResolver
    {
        $this->seed(\Database\Seeders\SectionSeeder::class);

        return app(SectionResolver::class);
    }

    public function test_exact_name_resolves_to_its_canonical_grade(): void
    {
        $r = $this->resolver()->resolve('Xavier');

        $this->assertTrue($r->isResolved());
        $this->assertSame('G8', $r->section->grade_level->value);
        $this->assertSame(SectionMatch::Exact, $r->match);
    }

    public function test_multi_word_name_resolves(): void
    {
        $r = $this->resolver()->resolve('Ignatius of Loyola');

        $this->assertSame('G8', $r->section->grade_level->value);
        $this->assertSame('Ignatius of Loyola', $r->section->name);
    }

    public function test_bare_ignatius_aliases_to_loyola(): void
    {
        // CLE writes just "Ignatius"; Science writes "Ignatius of Loyola".
        $r = $this->resolver()->resolve('Ignatius');

        $this->assertSame('Ignatius of Loyola', $r->section->name);
        $this->assertSame(SectionMatch::Alias, $r->match);
    }

    public function test_bare_loyola_aliases_to_loyola(): void
    {
        $r = $this->resolver()->resolve('Loyola');

        $this->assertSame('Ignatius of Loyola', $r->section->name);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('spellingVariants')]
    public function test_spelling_variants_resolve(string $raw, string $expected, string $grade): void
    {
        $r = $this->resolver()->resolve($raw);

        $this->assertTrue($r->isResolved(), "{$raw} should resolve");
        $this->assertSame($expected, $r->section->name);
        $this->assertSame($grade, $r->section->grade_level->value);
    }

    public static function spellingVariants(): array
    {
        return [
            'De Brito' => ['De Brito', 'De Britto', 'G7'],
            'Anchietta' => ['Anchietta', 'Anchieta', 'G9'],
            'Colombierre' => ['Colombierre', 'Colombiere', 'G10'],
            'Berchman' => ['Berchman', 'Berchmans', 'G10'],
        ];
    }

    public function test_embedded_grade_prefix_is_stripped(): void
    {
        // TLE writes "10Colombiere", "7Rubio".
        $this->assertSame('Colombiere', $this->resolver()->resolve('10Colombiere')->section->name);
    }

    public function test_embedded_grade_disagreeing_with_roster_is_flagged(): void
    {
        // The sheet claims G7 but Xavier is G8 - resolve, but surface the conflict.
        $r = $this->resolver()->resolve('7Xavier');

        $this->assertNotNull($r->reason);
        $this->assertStringContainsString('G7', $r->reason);
    }

    public function test_miki_is_flagged_not_silently_aliased(): void
    {
        // "Miki" appears in CLE/MAPEH/Science but is not in the 2026 roster.
        // Probably the former name of G7 Rubio - the registrar must confirm.
        $r = $this->resolver()->resolve('Miki');

        $this->assertFalse($r->isResolved());
        $this->assertSame(SectionMatch::Unresolved, $r->match);
        $this->assertStringContainsString('Rubio', $r->reason);
    }

    public function test_bare_paul_is_flagged_for_the_registrar(): void
    {
        // Math writes "Paul"; CLE/Science write "Miki". Both are Saint Paul Miki.
        $r = $this->resolver()->resolve('Paul');

        $this->assertFalse($r->isResolved());
        $this->assertStringContainsString('registrar', (string) $r->reason);
    }

    public function test_magis_alone_is_not_a_section(): void
    {
        // "Magis" is a modifier on three sections, not a name. Treating it as a
        // name is what produced the false "one name, three grades" conclusion.
        $r = $this->resolver()->resolve('Magis');

        $this->assertFalse($r->isResolved());
    }

    public function test_grade_qualified_magis_resolves_to_that_grades_magis_section(): void
    {
        // Science's Honor's Class column writes "G8 Magis", "G9 Magis", "G10 Magis".
        $resolver = $this->resolver();

        $this->assertSame('Ignatius of Loyola', $resolver->resolve('G8 Magis')->section->name);
        $this->assertSame('Kostka', $resolver->resolve('G9 Magis')->section->name);
        $this->assertSame('Faber', $resolver->resolve('G10 Magis')->section->name);
    }

    public function test_single_edit_typo_resolves_by_fuzzy_match(): void
    {
        $r = $this->resolver()->resolve('Pongracs');

        $this->assertTrue($r->isResolved());
        $this->assertSame('Pongracz', $r->section->name);
        $this->assertSame(SectionMatch::Fuzzy, $r->match);
    }

    public function test_two_edit_distance_is_refused(): void
    {
        // Regis<->Lewis and Faber<->Mayer are only 2 apart from each other, so a
        // 2-edit threshold could silently land on the wrong real section.
        $r = $this->resolver()->resolve('Levis');

        $this->assertFalse($r->isResolved());
    }

    public function test_near_miss_typo_is_reported_as_a_probable_section(): void
    {
        // Social Studies writes "De Brtio". Two edits from De Britto - too far to
        // auto-accept, but close enough that it is plainly a section, not a club.
        $r = $this->resolver()->resolve('De Brtio');

        $this->assertFalse($r->isResolved());
        $this->assertStringContainsString('De Britto', (string) $r->reason);
    }

    public function test_unrelated_text_is_not_reported_as_a_near_miss(): void
    {
        $r = $this->resolver()->resolve('Sports Club');

        $this->assertFalse($r->isResolved());
        $this->assertStringNotContainsString('Did you mean', (string) $r->reason);
    }

    public function test_unknown_name_is_unresolved_never_invented(): void
    {
        $r = $this->resolver()->resolve('Nonexistent');

        $this->assertFalse($r->isResolved());
        $this->assertSame(SectionMatch::Unresolved, $r->match);
    }

    public function test_resolves_a_parenthesised_comma_list(): void
    {
        $rs = $this->resolver()->resolveMany('(Arrowsmith, Jogues, Campion, Rubio)');

        $this->assertCount(4, $rs);
        $this->assertSame(['Arrowsmith', 'Jogues', 'Campion', 'Rubio'], array_map(fn ($r) => $r->section->name, $rs));
    }

    public function test_resolves_a_list_joined_with_and(): void
    {
        // Social Studies writes "Brebeuf, Morse, Owen and Rodriguez".
        $rs = $this->resolver()->resolveMany('Brebeuf, Morse, Owen and Rodriguez');

        $this->assertCount(4, $rs);
        $this->assertSame('Rodriguez', $rs[3]->section->name);
    }

    public function test_resolve_many_ignores_counts_and_noise(): void
    {
        $rs = $this->resolver()->resolveMany("3 sections\nOwen\nJerome\nMorse");

        $this->assertCount(3, $rs);
    }
}
