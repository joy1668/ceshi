<main class="main-content">
    <div class="category-bar">
        <?php foreach ($categories as $index => $cat): ?>
            <button class="category-chip <?= $index === 0 ? 'active' : '' ?>"><?= htmlspecialchars($cat) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="video-grid">
        <?php foreach ($videos as $video): ?>
        <div class="video-card">
            <div class="thumbnail" style="background: <?= $video['thumbnail_bg'] ?>;">
                <span class="duration"><?= $video['duration'] ?></span>
            </div>
            <div class="video-info">
                <div class="channel-avatar" style="background: <?= $video['channel_avatar'] ?>;">
                    <?= strtoupper($video['channel'][0]) ?>
                </div>
                <div class="video-details">
                    <h3 class="video-title"><?= htmlspecialchars($video['title']) ?></h3>
                    <p class="video-channel"><?= htmlspecialchars($video['channel']) ?></p>
                    <p class="video-meta"><?= $video['views'] ?> &bull; <?= $video['time'] ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>
