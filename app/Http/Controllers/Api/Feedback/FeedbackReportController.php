<?php

namespace App\Http\Controllers\Api\Feedback;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\CreateFeedbackReportRequest;
use App\Http\Resources\Feedback\FeedbackReportDetailResource;
use App\Http\Resources\Feedback\FeedbackReportResource;
use App\Http\Resources\Feedback\RequestLogResource;
use App\Models\FeedbackReport;
use App\Services\Feedback\FeedbackExporter;
use App\Services\Feedback\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FeedbackReportController extends Controller
{
    private const RELATIONS = ['reporter', 'branch', 'assignee', 'notes'];

    public function __construct(
        private readonly FeedbackService $service,
    ) {}

    /** Submit a report — any authenticated user. Degrade-never-reject (I2). */
    public function store(CreateFeedbackReportRequest $request): JsonResponse
    {
        $report = $this->service->createReport(
            $request->validated(),
            $request,
            $request->user(),
        );

        return response()->success(
            new FeedbackReportDetailResource($report->load(self::RELATIONS)),
            'Thanks — your report was received.',
        );
    }

    /** Triage inbox — filters, paginated, newest first. */
    public function index(Request $request): JsonResponse
    {
        $reports = FeedbackReport::query()
            ->with(self::RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->latest()
            ->paginate($request->integer('per_page') ?: 20);

        return response()->json([
            'data' => FeedbackReportResource::collection($reports->items()),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'from' => $reports->firstItem(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'to' => $reports->lastItem(),
                'total' => $reports->total(),
            ],
            'links' => [
                'first' => $reports->url(1),
                'last' => $reports->url($reports->lastPage()),
                'prev' => $reports->previousPageUrl(),
                'next' => $reports->nextPageUrl(),
            ],
        ]);
    }

    /** A reporter's own reports — so they can follow status (loop-close). */
    public function myReports(Request $request): JsonResponse
    {
        $reports = FeedbackReport::query()
            ->with(self::RELATIONS)
            ->where('reporter_id', $request->user()->id)
            ->latest()
            ->paginate($request->integer('per_page') ?: 20);

        return response()->json([
            'data' => FeedbackReportResource::collection($reports->items()),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'from' => $reports->firstItem(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'to' => $reports->lastItem(),
                'total' => $reports->total(),
            ],
            'links' => [
                'first' => $reports->url(1),
                'last' => $reports->url($reports->lastPage()),
                'prev' => $reports->previousPageUrl(),
                'next' => $reports->nextPageUrl(),
            ],
        ]);
    }

    /** Full detail — triage only. */
    public function show(FeedbackReport $feedbackReport): JsonResponse
    {
        $feedbackReport->load(self::RELATIONS);

        // "This page flagged in N other reports" — route is the robust key.
        $feedbackReport->related_count = FeedbackReport::query()
            ->where('route', $feedbackReport->route)
            ->where('id', '!=', $feedbackReport->id)
            ->count();

        return response()->success(new FeedbackReportDetailResource($feedbackReport));
    }

    /** Status and/or assignee — triage only. */
    public function update(Request $request, FeedbackReport $feedbackReport): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:new,triaged,in_progress,fixed,wont_fix'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $becameFixed = ($data['status'] ?? null) === 'fixed' && $feedbackReport->status !== 'fixed';

        $feedbackReport->fill($data)->save();

        // Close the loop ON THE TRANSITION only, best-effort — a notification
        // failure must never fail the triage save (C7 / P5).
        if ($becameFixed) {
            $this->service->notifyReporterFixed($feedbackReport);
        }

        return response()->success(
            new FeedbackReportDetailResource($feedbackReport->load(self::RELATIONS)),
            'Report updated.',
        );
    }

    /** Transcribe the voice note on demand — idempotent. Triage only. */
    public function transcribe(FeedbackReport $feedbackReport): JsonResponse
    {
        $this->service->transcribeReport($feedbackReport);

        return response()->success(
            new FeedbackReportDetailResource($feedbackReport->fresh()->load(self::RELATIONS)),
            $feedbackReport->fresh()->transcript ? 'Transcribed.' : 'No transcript available.',
        );
    }

    /** Correlated backend logs — request-id filtered by default, ±window fallback. */
    public function logs(Request $request, FeedbackReport $feedbackReport): JsonResponse
    {
        $window = $request->filled('windowMinutes') ? $request->integer('windowMinutes') : null;
        $logs = $this->service->logsForReport($feedbackReport, $window);

        return response()->success(RequestLogResource::collection($logs));
    }

    /**
     * AI-ready artefact. `fmt=md` (default) → a Markdown brief; `fmt=zip` → the
     * brief bundled with screenshots + voice note. Uses `fmt`, NOT `format` —
     * DRF-style frameworks reserve `format` (C6); Laravel treats it as the
     * response format and would mangle the route.
     */
    public function export(Request $request, FeedbackReport $feedbackReport, FeedbackExporter $exporter): Response
    {
        if ($request->query('fmt') === 'zip') {
            return response()
                ->download($exporter->zipPath($feedbackReport), "feedback-{$feedbackReport->id}.zip")
                ->deleteFileAfterSend();
        }

        return response($exporter->markdown($feedbackReport), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }
}
