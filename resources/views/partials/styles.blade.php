{{-- Inline CSS only: this page must render even when the app's assets, layout or session are broken. --}}
<style>
    :root {
        color-scheme: light dark;
        --bg: #f4f5fa;
        --card: #ffffff;
        --text: #1b2133;
        --muted: #5b6579;
        --border: #dfe3ee;
        --accent: #4f46e5;
        --accent-text: #ffffff;
        --code-bg: #eef0f8;
        --field: #ffffff;
        --ok: #0f7a4a;
        --warn: #9a5b00;
    }

    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #0e1120;
            --card: #171b2e;
            --text: #e7eaf5;
            --muted: #98a2b8;
            --border: #2a3049;
            --accent: #8b93ff;
            --accent-text: #0e1120;
            --code-bg: #222742;
            --field: #0f1325;
            --ok: #4ade9a;
            --warn: #f5b861;
        }
    }

    * { box-sizing: border-box; }

    html, body { margin: 0; padding: 0; }

    body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 16px;
        background: var(--bg);
        color: var(--text);
        font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }

    main {
        width: 100%;
        max-width: 520px;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 32px 28px;
        box-shadow: 0 10px 30px rgba(15, 20, 40, .08);
    }

    .moon { width: 44px; height: 44px; margin-bottom: 12px; color: var(--accent); }

    h1 { margin: 0 0 8px; font-size: 1.6rem; line-height: 1.25; }

    p { margin: 0 0 16px; color: var(--muted); }

    .reference { margin: 20px 0 24px; }

    .reference small { display: block; color: var(--muted); font-size: .85rem; margin-bottom: 6px; }

    .code-row { display: flex; gap: 8px; align-items: stretch; }

    .code {
        flex: 1;
        padding: 10px 14px;
        background: var(--code-bg);
        border: 1px solid var(--border);
        border-radius: 10px;
        font: 600 1.25rem/1.3 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        letter-spacing: .06em;
        user-select: all;
    }

    .hint { display: block; margin-top: 6px; color: var(--muted); font-size: .85rem; }

    button, .button {
        appearance: none;
        cursor: pointer;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px 16px;
        background: var(--card);
        color: var(--text);
        font: inherit;
        font-weight: 600;
        text-decoration: none;
    }

    button.primary, .button.primary {
        background: var(--accent);
        border-color: var(--accent);
        color: var(--accent-text);
    }

    form { margin: 0 0 20px; }

    label { display: block; font-weight: 600; margin-bottom: 4px; }

    textarea {
        width: 100%;
        min-height: 110px;
        resize: vertical;
        padding: 10px 12px;
        margin: 8px 0 12px;
        background: var(--field);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 10px;
        font: inherit;
    }

    textarea:focus, button:focus-visible, .button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .message { font-size: 1.1rem; margin: 0 0 20px; color: var(--text); }

    .message.ok { color: var(--ok); }

    .message.warn { color: var(--warn); }

    footer { display: flex; gap: 8px; flex-wrap: wrap; }

    .copy[hidden] { display: none; }
</style>
