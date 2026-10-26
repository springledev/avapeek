<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsPhotos;
use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Services\StorageService;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidDateException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    use FormatsPhotos;

    private const int MAX_ITEMS = 48;

    private const int MAX_CANDIDATE_YEARS = 50;

    public function __construct(protected StorageService $storageService) {}

    /**
     * Photos taken on the same month/day (in the viewer's time zone) in earlier years.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'tz' => ['required', 'string', 'timezone:all'],
        ]);

        $user = $request->user();
        $today = Carbon::createFromFormat('!Y-m-d', $validated['date'], 'UTC');
        $earliest = Photo::visibleTo($user)->whereNotNull('taken_at')->min('taken_at');

        $windows = $earliest === null
            ? []
            : $this->dayWindows($today, $validated['tz'], Carbon::parse($earliest, 'UTC')->year);

        if ($windows === []) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $query = Photo::visibleTo($user)->where(function ($q) use ($windows) {
            foreach ($windows as [$start, $end]) {
                $q->orWhere(function ($w) use ($start, $end) {
                    $w->where('taken_at', '>=', $start)->where('taken_at', '<', $end);
                });
            }
        });

        $total = (clone $query)->count();

        $viewerChildIds = $user->children()->pluck('children.id')->all();
        $memberships = $this->viewerMemberships($user);

        $photos = $query->with(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }])->withCount('comments')
            ->orderBy('taken_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::MAX_ITEMS)
            ->get();

        return response()->json([
            'data' => $photos->map(fn (Photo $photo) => array_merge($this->formatPhoto($photo, $viewerChildIds, $user->id, $memberships), [
                'thumbnail_url' => $this->storageService->presignedUrl($photo->thumbnail_path, 30),
            ])),
            'meta' => ['total' => $total],
        ]);
    }

    /**
     * UTC [start, end) windows of the same month/day in each earlier candidate year.
     * Years where the day does not exist (Feb 29) are skipped.
     *
     * @return list<array{Carbon, Carbon}>
     */
    private function dayWindows(Carbon $today, string $timezone, int $earliestYear): array
    {
        $firstYear = max($earliestYear - 1, $today->year - self::MAX_CANDIDATE_YEARS);
        $appTimezone = config('app.timezone');
        $windows = [];

        for ($year = $today->year - 1; $year >= $firstYear; $year--) {
            try {
                $start = Carbon::createSafe($year, $today->month, $today->day, 0, 0, 0, $timezone);
            } catch (InvalidDateException) {
                continue;
            }

            $end = $start->copy()->addDay()->startOfDay();
            $windows[] = [$start->setTimezone($appTimezone), $end->setTimezone($appTimezone)];
        }

        return $windows;
    }
}
