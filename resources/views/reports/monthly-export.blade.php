<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Tidemark Monthly Report — {{ $month }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #1a1a2e; margin: 40px; }
        h1 { color: #0f3460; font-size: 24px; border-bottom: 3px solid #16213e; padding-bottom: 8px; }
        h2 { color: #16213e; font-size: 16px; margin-top: 24px; }
        .scorecard { background: #f0f4f8; border-radius: 6px; padding: 16px; margin: 12px 0; }
        .scorecard .score { font-size: 36px; font-weight: bold; color: #0f3460; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th { background: #16213e; color: white; padding: 8px; text-align: left; font-size: 11px; }
        td { padding: 6px 8px; border-bottom: 1px solid #e0e0e0; font-size: 11px; }
        tr:nth-child(even) { background: #f8f9fa; }
        .footer { margin-top: 40px; padding-top: 12px; border-top: 1px solid #ccc; font-size: 10px; color: #888; }
        .brand { color: #0f3460; font-weight: bold; }
    </style>
</head>
<body>
    <h1><span class="brand">Tidemark</span> Monthly Report</h1>
    <p>{{ $tenant->name }} — {{ $month }}</p>
    <p>Generated: {{ $generated_at }}</p>

    <h2>Publication Health Scorecard</h2>
    <div class="scorecard">
        <div class="score">{{ $data['publication_health']['overall_score'] ?? 'N/A' }}</div>
        <p>Overall Health Score (0–100)</p>
        <p>Trend vs Last Month: {{ $data['publication_health']['trend_vs_last_month'] ?? 0 }}%</p>
        <p>Hours Saved by Automation: {{ $data['publication_health']['hours_saved'] ?? 0 }}</p>
        <p>Welfare Flags: {{ $data['publication_health']['welfare_flags_count'] ?? 0 }}</p>
        <p>Engagement Rate: {{ $data['publication_health']['engagement_rate'] ?? 0 }}%</p>
    </div>

    <h2>Section Comparison</h2>
    <table>
        <thead>
            <tr>
                <th>Section</th>
                <th>Avg Toxicity</th>
                <th>Trend</th>
                <th>Engagement Rate</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['section_comparison'] ?? [] as $section)
            <tr>
                <td>{{ $section['section'] }}</td>
                <td>{{ $section['avg_toxicity'] }}</td>
                <td>{{ $section['trend'] }}</td>
                <td>{{ $section['engagement_rate'] }}%</td>
            </tr>
            @empty
            <tr><td colspan="4">No section data available.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Top 10 Posts by Community Health</h2>
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Section</th>
                <th>Avg Toxicity</th>
                <th>Comments</th>
                <th>Success Factor</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['best_posts_all'] ?? [] as $post)
            <tr>
                <td>{{ $post['title'] }}</td>
                <td>{{ $post['section'] ?? '—' }}</td>
                <td>{{ $post['avg_toxicity'] }}</td>
                <td>{{ $post['total_comments'] }}</td>
                <td>{{ str_replace('_', ' ', $post['success_factor']) }}</td>
            </tr>
            @empty
            <tr><td colspan="5">No data available.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Bottom 10 Posts by Toxicity</h2>
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Section</th>
                <th>Avg Toxicity</th>
                <th>Comments</th>
                <th>Root Cause</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['worst_posts_all'] ?? [] as $post)
            <tr>
                <td>{{ $post['title'] }}</td>
                <td>{{ $post['section'] ?? '—' }}</td>
                <td>{{ $post['avg_toxicity'] }}</td>
                <td>{{ $post['total_comments'] }}</td>
                <td>{{ str_replace('_', ' ', $post['root_cause_flag'] ?? '—') }}</td>
            </tr>
            @empty
            <tr><td colspan="5">No data available.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Welfare Summary (Anonymised)</h2>
    <p>Journalists with welfare flags this month: {{ count($data['welfare_flags'] ?? []) }}</p>
    @if(!empty($data['welfare_flags']))
    <table>
        <thead>
            <tr>
                <th>Section</th>
                <th>Personal Attacks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['welfare_flags'] as $flag)
            <tr>
                <td>{{ $flag['section'] ?? '—' }}</td>
                <td>{{ $flag['personal_attacks_this_month'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <h2>Engagement Performance</h2>
    @if(!empty($data['engagement_leaderboard']))
    <table>
        <thead>
            <tr>
                <th>Section</th>
                <th>Total Engagements</th>
                <th>Posted</th>
                <th>Quality Score</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['engagement_leaderboard'] as $entry)
            <tr>
                <td>{{ $entry['section'] }}</td>
                <td>{{ $entry['total_engagements'] }}</td>
                <td>{{ $entry['posted'] }}</td>
                <td>{{ $entry['quality_score'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">
        <p>This report was automatically generated by <span class="brand">Tidemark</span>. Data reflects activity up to {{ $generated_at }}.</p>
    </div>
</body>
</html>
