<?php
// ============================================================
//  READER_CHALLENGES.PHP – Monthly reading goals and challenges
// ============================================================
?>
<script>
class ReaderChallenges {
    constructor(userId) {
        this.userId = userId;
        this.currentMonth = new Date().getMonth();
        this.currentYear = new Date().getFullYear();
    }

    // Fetch monthly challenge data
    async getMonthlyChallenge() {
        const response = await fetch('/reader/reader_ajax.php?action=get_monthly_challenge&user_id=' + this.userId);
        const data = await response.json();
        return data;
    }

    // Update challenge progress
    async updateProgress(pagesRead) {
        const formData = new FormData();
        formData.append('action', 'update_challenge_progress');
        formData.append('user_id', this.userId);
        formData.append('pages_read', pagesRead);
        const response = await fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        });
        return await response.json();
    }

    // Display challenge widget
    renderChallengeWidget(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        this.getMonthlyChallenge().then(data => {
            if (data.success) {
                const goal = data.goal;
                const progress = data.progress;
                const target = data.target;
                const percent = Math.min(100, Math.round((progress / target) * 100));

                let html = `
                    <div class="aw-challenge-widget">
                        <h4>📖 Monthly Reading Challenge</h4>
                        <p>${goal}</p>
                        <div class="aw-challenge-progress">
                            <div class="aw-challenge-bar" style="width: ${percent}%;"></div>
                            <span class="aw-challenge-percent">${percent}%</span>
                        </div>
                        <p class="aw-challenge-stats">${progress} / ${target} pages read</p>
                        <button class="aw-challenge-update" onclick="updateChallengeProgress()">📈 Update Progress</button>
                    </div>
                `;
                container.innerHTML = html;
            } else {
                container.innerHTML = `<p class="aw-challenge-empty">No active challenge this month.</p>`;
            }
        });
    }
}

// Update progress (called from the button)
function updateChallengeProgress() {
    const pages = prompt('How many pages did you read today?');
    if (pages && parseInt(pages) > 0) {
        const challenges = new ReaderChallenges(<?php echo isLoggedIn() ? $_SESSION['user_id'] : 0; ?>);
        challenges.updateProgress(parseInt(pages)).then(data => {
            if (data.success) {
                alert('✅ Progress updated!');
                location.reload();
            } else {
                alert('❌ Failed to update progress.');
            }
        });
    }
}
</script>