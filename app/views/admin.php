<?php
/**
 * @var string $tab
 * @var array  $cfg
 * @var array  $sources
 * @var array  $chunks
 * @var array  $inquiries
 * @var array  $logs
 * @var array  $stats
 * @var string $msg
 * @var string $mt
 */

use NoviSign\Chatbot\Settings;

$tabs = [
	'knowledge' => '📚 知識ソース',
	'chunks'    => '🧩 知識チャンク',
	'inquiries' => '📥 問い合わせ',
	'logs'      => '📈 チャットログ',
	'settings'  => '⚙ 設定',
	'tools'     => '🛠 ツール',
];
$has_keys = Settings::anthropic_key() !== '' && Settings::voyage_key() !== '';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>管理 — ezbot-local</title>
<style>
	:root { --bg:#f4f6f8; --card:#fff; --brand:#2b7de9; --brand-d:#1c5fc0; --ink:#1e2a33; --muted:#7a8894; --line:#e5e9ee; --ok:#1c7c46; --okbg:#e6f6ec; --err:#b3261e; --errbg:#fdecea; }
	* { box-sizing:border-box; }
	body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif; background:var(--bg); color:var(--ink); font-size:14px; }
	.top { background:var(--brand); color:#fff; padding:14px 22px; display:flex; align-items:center; gap:14px; }
	.top h1 { margin:0; font-size:17px; } .top a { color:#fff; text-decoration:none; font-size:13px; }
	.top .spacer { margin-left:auto; }
	.top .pill { background:#fff2; padding:4px 10px; border-radius:20px; font-size:12px; }
	.container { max-width:1000px; margin:0 auto; padding:20px; }
	nav.tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:18px; }
	nav.tabs a { padding:8px 14px; border-radius:8px; text-decoration:none; color:var(--ink); background:var(--card); border:1px solid var(--line); }
	nav.tabs a.on { background:var(--brand); color:#fff; border-color:var(--brand); }
	.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:18px; }
	.card h2 { margin:0 0 4px; font-size:15px; } .card p.desc { margin:0 0 16px; color:var(--muted); font-size:13px; }
	.flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:13.5px; }
	.flash.ok { background:var(--okbg); color:var(--ok); } .flash.err { background:var(--errbg); color:var(--err); }
	.stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
	.stat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:12px 16px; flex:1; min-width:120px; }
	.stat b { display:block; font-size:22px; } .stat span { color:var(--muted); font-size:12px; }
	label { display:block; font-weight:600; margin:12px 0 5px; font-size:13px; }
	input[type=text], input[type=url], input[type=password], input[type=number], textarea, select {
		width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; background:#fff; }
	textarea { min-height:70px; resize:vertical; }
	.row { display:flex; gap:14px; flex-wrap:wrap; } .row > div { flex:1; min-width:200px; }
	button, .btn { padding:9px 16px; border:0; border-radius:8px; background:var(--brand); color:#fff; font-size:13.5px; cursor:pointer; text-decoration:none; display:inline-block; }
	button.sub { background:#eef1f5; color:var(--ink); border:1px solid var(--line); }
	button.danger { background:#fbe9e7; color:var(--err); border:1px solid #f3c9c4; }
	table { width:100%; border-collapse:collapse; font-size:13px; }
	th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
	th { color:var(--muted); font-weight:600; }
	.tag { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; }
	.tag.indexed { background:var(--okbg); color:var(--ok); } .tag.error { background:var(--errbg); color:var(--err); }
	.tag.pending, .tag.crawling, .tag.queued { background:#fff4e0; color:#a5670b; }
	.tag.answered { background:var(--okbg); color:var(--ok); }
	.mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; color:var(--muted); word-break:break-all; }
	.muted { color:var(--muted); } .small { font-size:12px; }
	details summary { cursor:pointer; color:var(--brand-d); }
	.warn { background:#fff4e0; color:#a5670b; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:16px; }
	.inline { display:inline; }
</style>
</head>
<body>
<div class="top">
	<h1>🐱 ezbot-local 管理</h1>
	<span class="pill"><?= $has_keys ? 'APIキー設定済み ✓' : 'APIキー未設定 ⚠' ?></span>
	<div class="spacer"></div>
	<a href="/">← チャット画面へ</a>
</div>

<div class="container">
	<nav class="tabs">
		<?php foreach ($tabs as $key => $lbl): ?>
			<a href="/admin?tab=<?= h($key) ?>" class="<?= $tab === $key ? 'on' : '' ?>"><?= h($lbl) ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ($msg !== ''): ?>
		<div class="flash <?= $mt === 'err' ? 'err' : 'ok' ?>"><?= h($msg) ?></div>
	<?php endif; ?>

	<?php if (!$has_keys): ?>
		<div class="warn">⚠ Anthropic / Voyage の API キーが未設定です。「⚙ 設定」タブでキーを入力すると、取り込み（埋め込み）と回答生成が有効になります。</div>
	<?php endif; ?>

	<div class="stats">
		<div class="stat"><b><?= (int) $stats['active_chunks'] ?></b><span>有効な知識チャンク</span></div>
		<div class="stat"><b><?= (int) $stats['pending'] ?></b><span>未対応の問い合わせ</span></div>
		<div class="stat"><b><?= (int) $stats['logs'] ?></b><span>チャットログ件数</span></div>
	</div>

	<?php /* ============================ 知識ソース ============================ */ ?>
	<?php if ($tab === 'knowledge'): ?>
		<div class="card">
			<h2>知識を追加</h2>
			<p class="desc">URL・ファイル（txt / md / csv / html / pdf / docx / pptx）・貼り付けテキストを取り込むと、チャンク分割 → Voyage で埋め込み → 検索対象になります。</p>

			<form method="post" action="/admin/source/add" enctype="multipart/form-data">
				<label>種別</label>
				<select name="kind" id="kind" onchange="switchKind()">
					<option value="text">テキストを貼り付け</option>
					<option value="file">ファイルをアップロード</option>
					<option value="url">URL（配下を巡回して取り込み）</option>
				</select>

				<div id="f-text">
					<label>本文テキスト</label>
					<textarea name="text" placeholder="製品仕様・FAQ・マニュアル本文などを貼り付け"></textarea>
				</div>
				<div id="f-file" style="display:none">
					<label>ファイル</label>
					<input type="file" name="file" accept=".txt,.md,.csv,.html,.htm,.pdf,.docx,.pptx">
				</div>
				<div id="f-url" style="display:none">
					<label>URL</label>
					<input type="url" name="url" placeholder="https://example.com/docs">
					<p class="small muted">同じホストの配下ページを最大 <?= (int) $cfg['crawl_max_pages'] ?> ページまで巡回します（設定で変更可）。</p>
				</div>

				<label>ラベル（任意・出典表示に使用）</label>
				<input type="text" name="label" placeholder="例：NoviSign 料金表">
				<p style="margin-top:14px"><button type="submit">取り込む</button></p>
			</form>
		</div>

		<div class="card">
			<h2>登録済みソース（<?= count($sources) ?>）</h2>
			<?php if (!$sources): ?><p class="muted">まだありません。</p><?php else: ?>
			<table>
				<tr><th>ID</th><th>種別</th><th>ラベル / 参照</th><th>状態</th><th>チャンク</th><th>操作</th></tr>
				<?php foreach ($sources as $s): ?>
					<tr>
						<td><?= (int) $s->id ?></td>
						<td><?= h($s->type) ?></td>
						<td><?= h($s->label) ?><br><span class="mono"><?= h($s->ref) ?></span>
							<?php if (!empty($s->message)): ?><br><span class="small muted"><?= h($s->message) ?></span><?php endif; ?></td>
						<td><span class="tag <?= h($s->status) ?>"><?= h($s->status) ?></span></td>
						<td><?= (int) $s->chunks ?></td>
						<td>
							<form class="inline" method="post" action="/admin/source/reingest"><input type="hidden" name="id" value="<?= (int) $s->id ?>"><button class="sub" type="submit">再取込</button></form>
							<form class="inline" method="post" action="/admin/source/delete" onsubmit="return confirm('削除しますか？')"><input type="hidden" name="id" value="<?= (int) $s->id ?>"><button class="danger" type="submit">削除</button></form>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php endif; ?>
		</div>

		<script>
		function switchKind(){
			const k = document.getElementById('kind').value;
			document.getElementById('f-text').style.display = k==='text'?'block':'none';
			document.getElementById('f-file').style.display = k==='file'?'block':'none';
			document.getElementById('f-url').style.display  = k==='url' ?'block':'none';
		}
		switchKind();
		</script>
	<?php endif; ?>

	<?php /* ============================ チャンク ============================ */ ?>
	<?php if ($tab === 'chunks'): ?>
		<div class="card">
			<h2>知識チャンク（最新 <?= count($chunks) ?> 件）</h2>
			<p class="desc">検索の最小単位。学習した Q&amp;A（source_type=qa）や誤った内容はここから個別に削除できます。</p>
			<?php if (!$chunks): ?><p class="muted">まだありません。</p><?php else: ?>
			<table>
				<tr><th>ID</th><th>種別</th><th>出典</th><th>本文（先頭）</th><th></th></tr>
				<?php foreach ($chunks as $c): ?>
					<tr>
						<td><?= (int) $c->id ?></td>
						<td><span class="tag"><?= h($c->source_type) ?></span></td>
						<td class="small"><?= h(mb_strimwidth((string) $c->source_label, 0, 40, '…')) ?></td>
						<td><details><summary><?= h(mb_strimwidth((string) $c->chunk_text, 0, 60, '…')) ?></summary><div class="small" style="white-space:pre-wrap;margin-top:6px"><?= h($c->chunk_text) ?></div></details></td>
						<td><form method="post" action="/admin/chunk/delete" onsubmit="return confirm('削除しますか？')"><input type="hidden" name="id" value="<?= (int) $c->id ?>"><button class="danger" type="submit">削除</button></form></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php /* ============================ 問い合わせ ============================ */ ?>
	<?php if ($tab === 'inquiries'): ?>
		<div class="card">
			<h2>問い合わせ（知識に無く、メール取得したもの）</h2>
			<p class="desc">担当者が回答を書いて保存すると、その Q&amp;A が知識として学習され、次回から自動回答できるようになります（＝ヒューマン・イン・ザ・ループ学習）。</p>
			<?php if (!$inquiries): ?><p class="muted">まだありません。</p><?php else: ?>
			<?php foreach ($inquiries as $q): ?>
				<div style="border:1px solid var(--line); border-radius:10px; padding:14px; margin-bottom:12px;">
					<div><span class="tag <?= h($q->status) ?>"><?= h($q->status) ?></span> <span class="muted small">#<?= (int) $q->id ?> ／ <?= h($q->email) ?> ／ <?= h($q->created_at) ?></span></div>
					<div style="white-space:pre-wrap; margin:8px 0; font-size:13.5px;"><?= h($q->question) ?></div>
					<?php if ($q->status === 'answered'): ?>
						<div class="small" style="background:var(--okbg); padding:10px; border-radius:8px; white-space:pre-wrap;"><b>回答:</b> <?= h($q->answer_text) ?></div>
					<?php else: ?>
						<form method="post" action="/admin/inquiry/answer">
							<input type="hidden" name="id" value="<?= (int) $q->id ?>">
							<textarea name="answer" placeholder="この質問への回答を書く（保存すると知識として学習されます）"></textarea>
							<p style="margin-top:8px"><button type="submit">回答して学習</button></p>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php /* ============================ ログ ============================ */ ?>
	<?php if ($tab === 'logs'): ?>
		<div class="card">
			<h2>チャットログ（<?= count($logs) ?> 件）</h2>
			<p class="desc">全チャットの軽量ログ。self-solve（自己解決）率や不満度(heat)の把握に使います。</p>
			<?php if (!$logs): ?><p class="muted">まだありません。</p><?php else: ?>
			<table>
				<tr><th>日時</th><th>質問</th><th>種別</th><th>解決</th><th>score</th><th>heat</th></tr>
				<?php foreach (array_reverse($logs) as $l): ?>
					<tr>
						<td class="small mono"><?= h($l->created_at) ?></td>
						<td class="small"><?= h(mb_strimwidth((string) $l->question, 0, 50, '…')) ?></td>
						<td class="small"><?= h($l->type) ?></td>
						<td><?= ((int) $l->answered) ? '✓' : '—' ?></td>
						<td class="small"><?= number_format((float) $l->top_score, 3) ?></td>
						<td class="small"><?= (int) $l->heat ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php /* ============================ 設定 ============================ */ ?>
	<?php if ($tab === 'settings'): ?>
		<form method="post" action="/admin/settings">
			<div class="card">
				<h2>API キー</h2>
				<p class="desc">Anthropic（回答生成）と Voyage AI（埋め込み）。別アカウント・別キーです。</p>
				<div class="row">
					<div><label>Anthropic API キー</label><input type="password" name="anthropic_key" value="<?= h($cfg['anthropic_key']) ?>" placeholder="sk-ant-..."></div>
					<div><label>Anthropic モデル</label><input type="text" name="anthropic_model" value="<?= h($cfg['anthropic_model']) ?>"></div>
				</div>
				<div class="row">
					<div><label>Voyage API キー</label><input type="password" name="voyage_key" value="<?= h($cfg['voyage_key']) ?>" placeholder="pa-..."></div>
					<div><label>Voyage モデル</label><input type="text" name="voyage_model" value="<?= h($cfg['voyage_model']) ?>"></div>
				</div>
			</div>
			<div class="card">
				<h2>RAG パラメータ</h2>
				<div class="row">
					<div><label>類似度しきい値 (threshold)</label><input type="text" name="threshold" value="<?= h($cfg['threshold']) ?>"></div>
					<div><label>取得件数 (top_k)</label><input type="number" name="top_k" value="<?= (int) $cfg['top_k'] ?>"></div>
					<div><label>確認質問の上限 (max_clarify)</label><input type="number" name="max_clarify" value="<?= (int) $cfg['max_clarify'] ?>"></div>
				</div>
				<div class="row">
					<div><label>チャンクサイズ</label><input type="number" name="chunk_size" value="<?= (int) $cfg['chunk_size'] ?>"></div>
					<div><label>チャンク重なり</label><input type="number" name="chunk_overlap" value="<?= (int) $cfg['chunk_overlap'] ?>"></div>
					<div><label>最大トークン</label><input type="number" name="max_tokens" value="<?= (int) $cfg['max_tokens'] ?>"></div>
					<div><label>URL巡回の最大ページ</label><input type="number" name="crawl_max_pages" value="<?= (int) $cfg['crawl_max_pages'] ?>"></div>
				</div>
			</div>
			<div class="card">
				<h2>チャットボットの文言</h2>
				<div class="row">
					<div><label>ウィジェットのタイトル</label><input type="text" name="widget_title" value="<?= h($cfg['widget_title']) ?>"></div>
				</div>
				<label>あいさつ文</label><textarea name="widget_greeting"><?= h($cfg['widget_greeting']) ?></textarea>
				<label>知識に無いときの応答（メール取得）</label><textarea name="fallback"><?= h($cfg['fallback']) ?></textarea>
				<label>メール取得後のお礼</label><textarea name="email_thanks"><?= h($cfg['email_thanks']) ?></textarea>
			</div>
			<p><button type="submit">設定を保存</button></p>
		</form>
	<?php endif; ?>

	<?php /* ============================ ツール ============================ */ ?>
	<?php if ($tab === 'tools'): ?>
		<div class="card">
			<h2>知識ベースのエクスポート</h2>
			<p class="desc">ソース＋チャンク（埋め込み込み）を JSON で書き出します。バックアップや環境間の移行に。</p>
			<a class="btn" href="/admin/export">JSON をダウンロード</a>
		</div>
		<div class="card">
			<h2>インポート</h2>
			<p class="desc">エクスポートした JSON を取り込みます。merge=既存に追加（重複排除）、replace=全消去してから取り込み。</p>
			<form method="post" action="/admin/import" enctype="multipart/form-data">
				<label>JSON ファイル</label><input type="file" name="file" accept=".json">
				<label>モード</label>
				<select name="mode"><option value="merge">merge（追加）</option><option value="replace">replace（置換）</option></select>
				<p style="margin-top:12px"><button type="submit">インポート</button></p>
			</form>
		</div>
	<?php endif; ?>

	<p class="muted small" style="text-align:center; margin-top:30px">
		ezbot-local — WordPress 不要の学習用スタンドアロン版。コアロジックは本番プラグイン（novisign-chatbot）と同一です。
	</p>
</div>
</body>
</html>
