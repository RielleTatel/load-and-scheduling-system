<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\SystemConstant;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    /**
     * The 36 JHS sections for SY 2026-2027, from the registrar's
     * "List of Class Mods and Teacher-Partners 2026" — the authoritative roster.
     *
     * Nine sections per grade, and **no name is reused across grades**. That
     * uniqueness is what lets PdfExtractionService recover a section's grade from
     * its name alone (the plantilla sheets carry grade only by column position).
     * SeedTest asserts it; if a future year breaks it, extraction must fall back
     * to Chair confirmation rather than guessing.
     *
     * [short name, registrar's full name, room, is magis, moderator, teacher-partner]
     *
     * Moderator and teacher-partner come from the same registrar list. Four of the
     * seven plantilla sheets never record a moderator, so this is the only
     * complete source for that assignment.
     */
    private array $sections = [
        'G7' => [
            ['Arrowsmith', 'Saint Edmund Arrowsmith', '206', false, 'Frizie B. Dealagdon', 'Angel Joy Suzette H. Lauresta'],
            ['Bellarmine', 'Saint Robert Bellarmine', '302', false, 'Nerissa T. Brigoli', 'Jirah R. Macalintal'],
            ['Campion', 'Saint Edmund Campion', '303', false, 'Sheila Mae B. Alas-as', 'Mark Brian D. Gumandao'],
            ['Claver', 'Saint Peter Claver', '304', false, 'Abu-Masroor B. Jumaari', 'Manilyn C. Ramos'],
            ['De Britto', 'Saint John de Britto', '305', false, 'Francheska June Naomi A. Francisco', 'Chantie A. Chiong'],
            ['Jogues', 'Saint Isaac Jogues', '306', false, 'Roshielle Sheera M. Peña', 'Maryjean G. Magsayo'],
            ['Pongracz', 'Saint Stephen Pongracz', '307', false, 'Napisa D. Hatab', 'Via Mariz S. Espejo'],
            ['Regis', 'Saint John Francis Regis', '308', false, 'Mary Cris Asdali', 'Hazel G. Sumicad'],
            ['Rubio', 'Saint José María Rubio', '309', false, 'Cristie R. Delos Reyes', 'Monica Jane S. Bayona'],
        ],
        'G8' => [
            ['Borgia', 'Saint Francis Borgia', '406', false, 'Deli Dell A. Damasin', 'Arvin G. Sususco'],
            ['Briant', 'Saint Alexander Briant', '407', false, 'Maylyn A. Vicente', 'Sapphire T. Sienes'],
            ['Goupil', 'Saint Rene Goupil', '408', false, 'Kyla J. Adil', 'Marlo A. Cuario'],
            ['Ignatius of Loyola', 'Saint Ignatius of Loyola', '405', true, 'Mhuammar A. Magasa', 'Dave M. Natividad'],
            ['Lewis', 'Saint David Lewis', '409', false, 'Mark Angelo R. Layon', 'Larry Peter D. Gantalao'],
            ['Ogilvie', 'Saint John Ogilvie', '410', false, 'Abigail Joyce L. Vendiola', 'Ma. Julianna Yzabel G. Ragay'],
            ['Pignatelli', 'Saint Joseph Pignatelli', '411', false, 'Mermal A. Romanggar Jr.', 'Kian Rhyne R. Sechico'],
            ['Realino', 'Saint Bernardine Realino', '412', false, 'Rhea G. Miñoza', 'Mary Gale T. Comeros'],
            ['Xavier', 'Saint Francis Xavier', '413', false, 'Alex Julianne C. Bernabe', 'Anthony Dave M. Alibasa'],
        ],
        'G9' => [
            ['Anchieta', 'Saint Jose de Anchieta', '507', false, 'Angelica M Singson', 'Noemie Nadine S. Gregorio'],
            ['Brebeuf', 'Saint John de Brebeuf', '508', false, 'Vincent B. Galvez', 'Dave C. Ramos'],
            ['Evans', 'Saint Philip Evans', '509', false, 'Isah Mae A. Antonio', 'Jovian R. Laura'],
            ['Garnet', 'Saint Thomas Garnet', '510', false, 'Rica R. Amor', 'Mohammad K. Wahid'],
            ['Jerome', 'Saint Francis Jerome', '511', false, 'Wendell Jay T. Huenda', 'Robyn P. Tolentino'],
            ['Kostka', 'Saint Stanislaus Kostka', '513', true, 'Karen Alvarez', 'Maria Sara L. Velasco'],
            ['Morse', 'Saint Henry Morse', '512', false, 'Angelie Joy E. Sanson', 'Reyna Mae C. Lomocso'],
            ['Owen', 'Saint Nicholas Owen', '401', false, 'Camille Grace R. Lasay', 'Nhel Mathew P. Divinagracia'],
            ['Rodriguez', 'Saint Alphonsus Rodriguez', '402', false, 'Rizel Grace B. Villanueva', 'Christian Dale A. Punzalan'],
        ],
        'G10' => [
            ['Berchmans', 'Saint John Berchmans', '313', false, 'Gia Nicole F. Jolapong', 'Emmanuel L. Gonzaga'],
            ['Canisius', 'Saint Peter Canisius', '310', false, 'Mary Ann A. Francisco', 'Mary Jane R. Ajijun'],
            ['Chabanel', 'Saint Noel Chabanel', '314', false, 'Jean Rose T. Manuel', 'Lyka D. Calago'],
            ['Colombiere', 'Saint Claude la Colombiere', '311', false, 'James Ryan C. Seneriches, SJ', 'Leilyn Erle G. Aurestila'],
            ['Daniel', 'Saint Anthony Daniel', '312', false, 'Belen A. Lim', 'Jenny Ceballos'],
            ['Faber', 'Saint Peter Faber', '404', true, 'Roel D. Agustin Jr.', 'Jayson M. Mahilum'],
            ['Hurtado', 'Saint Albert Hurtado', '415', false, 'Ken A. Cañales', 'Esther Q. Mondido'],
            ['Mayer', 'Blessed Rupert Mayer', '416', false, 'Fhaijah H. Abduraja', 'Virginia C. Guiñabo'],
            ['Southwell', 'Saint Robert Southwell', '414', false, 'Ivy Q. Delos Reyes', 'Miu Mae O. Mosones'],
        ],
    ];

    public function run(): void
    {
        $schoolYear = SystemConstant::get('current_school_year', '2026-2027');
        $keep = [];

        foreach ($this->sections as $grade => $rows) {
            foreach ($rows as [$name, $fullName, $room, $isMagis, $moderator, $partner]) {
                $keep[] = Section::updateOrCreate(
                    ['school_year' => $schoolYear, 'grade_level' => $grade, 'name' => $name],
                    [
                        'full_name' => $fullName, 'room' => $room, 'is_magis' => $isMagis,
                        'moderator_name' => $moderator, 'teacher_partner_name' => $partner,
                    ],
                )->id;
            }
        }

        $this->pruneStaleSections($keep, $schoolYear);
    }

    /**
     * Databases seeded before 2026-09-02 hold 85 sections — 49 of them the same
     * section filed under a grade it does not belong to. Adding the correct 36
     * alongside them is not enough; the wrong ones stay selectable otherwise.
     *
     * A stale section that something still references is left in place and
     * reported: dropping it would take a teacher's assignment with it, which is
     * the Chair's call, not the seeder's.
     *
     * Scoped to the seeded year: other school years are their own rosters, not
     * stale rows, and deleting them would take their history with them.
     *
     * @param  array<int, int>  $keep
     */
    private function pruneStaleSections(array $keep, string $schoolYear): void
    {
        $stale = Section::where('school_year', $schoolYear)->whereNotIn('id', $keep)->get();

        foreach ($stale as $section) {
            $inUse = $section->teacherAssignments()->exists()
                || $section->moderatorAssignment()->exists()
                || $section->honorsAssignments()->exists();

            if ($inUse) {
                $this->command?->warn(
                    "Kept stale section {$section->grade_level->value} {$section->name} — it still has "
                    . 'assignments. Reassign them, then re-run this seeder.'
                );

                continue;
            }

            $section->delete();
        }
    }
}
