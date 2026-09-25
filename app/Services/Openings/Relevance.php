<?php

namespace App\Services\Openings;

use App\Models\BranchOpeningAnswer;
use Illuminate\Support\Collection;

/**
 * Which lines of the checklist still need asking, given the answers so far.
 *
 * Some questions only make sense after another answer. "Has cover been
 * arranged for absent staff?" means nothing once "All scheduled staff have
 * reported" is Yes; the low-stock list means nothing when every ingredient is
 * there. A line carries a `show_if` that says when it is asked:
 *
 *   {"when": "staff_reported", "is": "problem"}
 *       asked only if that line is answered that way
 *
 *   {"any_problem": ["Core food stock", "Drinks and packaging"]}
 *       asked only if some line in those groups or sections is a problem
 *
 * A line that is not asked is not required, is not a problem, and is not
 * counted. The frontend evaluates the same rules to show and hide lines as
 * they are answered (lib/utils/openingRelevance.ts); this is the version the
 * server trusts.
 *
 * A rule that cannot be followed (a key that is not on the checklist, or a
 * loop) leaves the line asked. Asking one question too many is better than
 * losing one.
 */
final class Relevance
{
    /**
     * @param  Collection<int, BranchOpeningAnswer>  $answers
     * @return array<int, bool> answer id => asked
     */
    public static function of(Collection $answers): array
    {
        $byKey = $answers->keyBy('key');
        $memo = [];
        $visiting = [];

        $asked = function (BranchOpeningAnswer $answer) use (&$asked, &$memo, &$visiting, $byKey, $answers): bool {
            if (isset($memo[$answer->key])) {
                return $memo[$answer->key];
            }

            $rule = $answer->show_if;
            if (! is_array($rule) || $rule === [] || isset($visiting[$answer->key])) {
                return $memo[$answer->key] = true;
            }

            $visiting[$answer->key] = true;

            if (isset($rule['when'])) {
                $other = $byKey->get($rule['when']);
                $result = $other === null
                    ? true
                    : $asked($other) && $other->answer === ($rule['is'] ?? BranchOpeningAnswer::PROBLEM);
            } elseif (isset($rule['any_problem'])) {
                $scopes = (array) $rule['any_problem'];
                $result = $answers->contains(fn (BranchOpeningAnswer $a) => $a->key !== $answer->key
                    && $a->isCheck()
                    && $a->answer === BranchOpeningAnswer::PROBLEM
                    && (in_array($a->group, $scopes, true) || in_array($a->section, $scopes, true))
                    && $asked($a));
            } else {
                $result = true;
            }

            unset($visiting[$answer->key]);

            return $memo[$answer->key] = $result;
        };

        $map = [];
        foreach ($answers as $answer) {
            $map[$answer->id] = $asked($answer);
        }

        return $map;
    }
}
