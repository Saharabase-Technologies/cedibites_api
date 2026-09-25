<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchOpeningAnswerResource;
use App\Http\Resources\BranchOpeningResource;
use App\Models\Branch;
use App\Models\BranchOpeningAnswer;
use App\Models\BranchOpeningPhoto;
use App\Rules\EvidenceMedia;
use App\Services\Openings\BranchOpeningService;
use App\Services\Openings\OpeningException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The branch manager opening the branch, and the staff waiting on it.
 *
 * Every route is behind `branch.access`, so a manager can only ever open the
 * branch they are assigned to. Only today's opening can be touched: a line
 * from another day, or another branch, is a 404.
 */
class BranchOpeningController extends Controller
{
    public function __construct(
        private readonly BranchOpeningService $openings,
    ) {}

    /** Today's opening with every line, for the manager doing it. */
    public function show(Branch $branch): JsonResponse
    {
        return response()->success(new BranchOpeningResource($branch, $this->openings->current($branch)));
    }

    /** Where today's opening stands, for staff waiting to start work. No answers. */
    public function status(Branch $branch): JsonResponse
    {
        return response()->success(new BranchOpeningResource($branch, $this->openings->current($branch), withAnswers: false));
    }

    public function start(Request $request, Branch $branch): JsonResponse
    {
        return $this->attempt(function () use ($request, $branch) {
            $opening = $this->openings->start($branch, $request->user(), $this->via($request));

            return new BranchOpeningResource($branch, $opening);
        });
    }

    public function answer(Request $request, Branch $branch, BranchOpeningAnswer $answer): JsonResponse
    {
        $this->assertToday($branch, $answer);

        $data = $request->validate([
            'answer' => ['nullable', 'string', 'in:ok,problem,na'],
            'value' => ['nullable'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => new BranchOpeningAnswerResource(
            $this->openings->answer($answer, $request->user(), $data)
        ));
    }

    /** "Yes to all" for one set of questions. */
    public function answerGroup(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'section' => ['required', 'string', 'max:60'],
            'group' => ['nullable', 'string', 'max:60'],
        ]);

        $opening = $this->openings->current($branch);
        if ($opening === null || ! $opening->isStarted()) {
            return response()->json(['code' => 'not_started', 'message' => 'Start the checklist first.'], 422);
        }

        return $this->attempt(fn () => BranchOpeningAnswerResource::collection(
            $this->openings->answerGroup($opening, $data['section'], $data['group'] ?? null, $request->user())
        ));
    }

    public function complete(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $opening = $this->openings->current($branch);

        if ($opening === null) {
            return response()->json(['code' => 'not_started', 'message' => 'Start the checklist first.'], 422);
        }

        return $this->attempt(function () use ($request, $branch, $opening, $data) {
            $done = $this->openings->complete($opening, $request->user(), $this->via($request), $data['note'] ?? null);

            return new BranchOpeningResource($branch, $done);
        });
    }

    public function resolve(Request $request, Branch $branch, BranchOpeningAnswer $answer): JsonResponse
    {
        $this->assertToday($branch, $answer);

        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => new BranchOpeningAnswerResource(
            $this->openings->resolve($answer, $request->user(), $data['note'])
        ));
    }

    public function storePhoto(Request $request, Branch $branch, BranchOpeningAnswer $answer): JsonResponse
    {
        $this->assertToday($branch, $answer);

        $request->validate(['file' => ['required', 'file', new EvidenceMedia]]);

        return $this->attempt(function () use ($request, $answer) {
            $this->openings->attachPhoto($answer, $request->file('file'), $request->user());

            return new BranchOpeningAnswerResource($answer->fresh(['photos', 'answerer', 'resolver']));
        }, 201);
    }

    public function destroyPhoto(Request $request, Branch $branch, BranchOpeningPhoto $photo): JsonResponse
    {
        $this->assertToday($branch, $photo->answer);

        return $this->attempt(function () use ($request, $photo) {
            $answer = $photo->answer;
            $this->openings->deletePhoto($photo, $request->user());

            return new BranchOpeningAnswerResource($answer->fresh(['photos', 'answerer', 'resolver']));
        });
    }

    /** Which screen it came from, for the record. */
    private function via(Request $request): string
    {
        return $request->input('via') === 'pos' ? 'pos' : 'portal';
    }

    private function assertToday(Branch $branch, ?BranchOpeningAnswer $answer): void
    {
        $opening = $answer?->opening;

        abort_if(
            $opening === null
                || (int) $opening->branch_id !== (int) $branch->id
                || $opening->business_date->toDateString() !== $this->openings->businessDate(),
            404,
        );
    }

    private function attempt(callable $action, int $status = 200): JsonResponse
    {
        try {
            $result = $action();
        } catch (OpeningException $e) {
            return response()->json(['code' => $e->reason, 'message' => $e->getMessage()] + $e->details, 422);
        }

        return response()->json(['data' => $result], $status);
    }
}
