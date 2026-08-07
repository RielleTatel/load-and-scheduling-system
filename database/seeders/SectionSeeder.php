<?php

namespace Database\Seeders;

use App\Models\Section;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    /**
     * Sections per grade, from docs/Documentation/JHS Sections Directory.md.
     * Canonical spellings (De Britto, Anchieta, Colombiere, Berchmans).
     */
    private array $sections = [
        'G7' => ['Arrowsmith', 'Bellarmine', 'Borgia', 'Briant', 'Campion', 'Claver', 'De Britto', 'Ignatius', 'Jogues', 'Lewis', 'Miki', 'Ogilvie', 'Paul', 'Pignatelli', 'Pongracz', 'Realino', 'Regis', 'Rubio', 'Xavier'],
        'G8' => ['Anchieta', 'Arrowsmith', 'Bellarmine', 'Berchmans', 'Borgia', 'Brebeuf', 'Briant', 'Campion', 'Canisius', 'Chabanel', 'Claver', 'Colombiere', 'De Britto', 'Evans', 'Faber', 'Garnet', 'Goupil', 'Hurtado', 'Ignatius', 'Jerome', 'Jogues', 'Kostka', 'Lewis', 'Loyola', 'Mayer', 'Miki', 'Morse', 'Ogilvie', 'Owen', 'Pignatelli', 'Pongracz', 'Realino', 'Regis', 'Rodriguez', 'Southwell', 'Xavier'],
        'G9' => ['Anchieta', 'Berchmans', 'Brebeuf', 'Campion', 'Canisius', 'Chabanel', 'Colombiere', 'Daniel', 'Evans', 'Faber', 'Garnet', 'Goupil', 'Hurtado', 'Jerome', 'Kostka', 'Mayer', 'Morse', 'Owen', 'Rodriguez', 'Southwell'],
        'G10' => ['Berchmans', 'Canisius', 'Chabanel', 'Colombiere', 'Daniel', 'Faber', 'Hurtado', 'Jogues', 'Mayer', 'Southwell'],
    ];

    public function run(): void
    {
        foreach ($this->sections as $grade => $names) {
            foreach ($names as $name) {
                Section::updateOrCreate(['grade_level' => $grade, 'name' => $name]);
            }
        }
    }
}
