<?php
// ============================================================
//  READER_CHALLENGES.PHP – Monthly Reading Challenges
//  Complete module with backend endpoints and frontend widget.
//  Include this file in reader.php or use as a standalone page.
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reading_groups.php';

// --- Handle AJAX requests ---
if (isset($_POST['action']) || isset($_GET['action'])) {
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in.']);
        exit;
    }

    $user_id = $_SESSION['user_id'];

    // ----------------------------------------
    // Get Monthly Challenge
    // ----------------------------------------
    if ($action === 'get_monthly_challenge') {
        $month = date('m');
        $year = date('Y');

        // Try to get existing challenge
        $stmt = $db->prepare("
            SELECT id, goal, target, progress, completed
            FROM reading_challenges
            WHERE user_id = ? AND month = ? AND year = ?
        ");
        $stmt->execute([$user_id, $month, $year]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge) {
            // Create default challenge
            $default_target = 30; // pages
            $default_goal = "Read $default_target pages this month";
            $stmt = $db->prepare("
                INSERT INTO reading_challenges (user_id, month, year, goal, target, progress)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([$user_id, $month, $year, $default_goal, $default_target]);
            $challenge = [
                'id' => $db->lastInsertId(),
                'goal' => $default_goal,
                'target' => $default_target,
                'progress' => 0,
                'completed' => 0
            ];
        }

        echo json_encode([
            'success' => true,
            'goal' => $challenge['goal'],
            'target' => (int)$challenge['target'],
            'progress' => (int)$challenge['progress'],
            'completed' => (int)$challenge['completed'],
        ]);
        exit;
    }

    // ----------------------------------------
    // Update Challenge Progress
    // ----------------------------------------
    if ($action === 'update_challenge_progress') {
        $pages_read = isset($_POST['pages_read']) ? (int)$_POST['pages_read'] : 0;
        if ($pages_read <= 0) {
            echo json_encode(['success' => false, 'error' => 'Pages read must be positive.']);
            exit;
        }

        $month = date('m');
        $year = date('Y');

        // Get challenge
        $stmt = $db->prepare("
            SELECT id, target, progress, completed
            FROM reading_challenges
            WHERE user_id = ? AND month = ? AND year = ?
        ");
        $stmt->execute([$user_id, $month, $year]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge) {
            // Create default
            $default_target = 30;
            $default_goal = "Read $default_target pages this month";
            $stmt = $db->prepare("
                INSERT INTO reading_challenges (user_id, month, year, goal, target, progress)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $month, $year, $default_goal, $default_target, $pages_read]);
            $new_progress = $pages_read;
            $challenge = ['target' => $default_target, 'progress' => $pages_read, 'id' => $db->lastInsertId()];
        } else {
            $new_progress = $challenge['progress'] + $pages_read;
            $stmt = $db->prepare("
                UPDATE reading_challenges
                SET progress = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_progress, $challenge['id']]);
        }

        // Check if challenge is complete
        if ($new_progress >= $challenge['target'] && $challenge['completed'] == 0) {
            $stmt = $db->prepare("UPDATE reading_challenges SET completed = 1 WHERE id = ?");
            $stmt->execute([$challenge['id']]);

            // Award achievement
            $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
            $stmt->execute([$user_id, 'monthly_challenge_completed']);

            // Award XP (using gamification functions if available)
            if (function_exists('updateUserStats')) {
                updateUserStats($user_id, 0, 'challenge', 1);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ----------------------------------------
    // Get Challenge History (optional)
    // ----------------------------------------
    if ($action === 'get_challenge_history') {
        $stmt = $db->prepare("
            SELECT month, year, goal, target, progress, completed
            FROM reading_challenges
            WHERE user_id = ?
            ORDER BY year DESC, month DESC
            LIMIT 12
        ");
        $stmt->execute([$user_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'history' => $history]);
        exit;
    }

    // Invalid action
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}
?>
<!-- ============================================================
     FRONTEND – Challenge Widget and Modal
     ============================================================ -->
<style>
.aw-challenge-widget {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px;
    margin: 16px 0;
    box-shadow: var(--shadow);
    transition: all 0.3s;
}
.aw-challenge-widget h4 {
    margin: 0 0 8px;
    font-size: 1.1rem;
    color: var(--dark);
}
.aw-challenge-widget p {
    margin: 4px 0;
    color: var(--text-light);
    font-size: 0.95rem;
}
.aw-challenge-progress {
    position: relative;
    height: 20px;
    background: var(--border);
    border-radius: 10px;
    overflow: hidden;
    margin: 8px 0;
}
.aw-challenge-bar {
    height: 100%;
    background: var(--rose);
    transition: width 0.4s ease;
    border-radius: 10px;
}
.aw-challenge-percent {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--text);
}
.aw-challenge-stats {
    font-size: 0.85rem;
    color: var(--text-light);
    margin: 4px 0 12px;
}
.aw-challenge-update {
    background: var(--rose);
    color: white;
    border: none;
    padding: 6px 16px;
    border-radius: 20px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}
.aw-challenge-update:hover {
    background: var(--rose-dark);
    transform: scale(1.02);
}
.aw-challenge-empty {
    text-align: center;
    color: var(--text-light);
    padding: 20px 0;
}
#awChallengeModal .modal-wrapper {
    max-width: 500px;
}
#awChallengeModal .modal-body {
    padding: 12px 0;
}
#awChallengeModal .modal-footer {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}
</style>

<div id="awChallengeModal" class="modal-wrapper">
    <div class="modal-header">
        <h3>📖 Monthly Reading Challenge</h3>
        <button class="modal-close" onclick="closeChallengeModal()">&times;</button>
    </div>
    <div class="modal-body">
        <div id="awChallengeWidget"></div>
        <div id="awChallengeHistory" style="margin-top:16px;"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeChallengeModal()">Close</button>
    </div>
</div>

<script>
class ReaderChallenges {
    constructor(userId) {
        this.userId = userId;
        this.apiBase = '/reader/reader_challenges.php';
    }

    // --- Fetch current challenge ---
    async getMonthlyChallenge() {
        const resp = await fetch(this.apiBase + '?action=get_monthly_challenge', {
            credentials: 'same-origin'
        });
        return resp.json();
    }

    // --- Update progress ---
    async updateProgress(pagesRead) {
        const formData = new FormData();
        formData.append('action', 'update_challenge_progress');
        formData.append('pages_read', pagesRead);
        const resp = await fetch(this.apiBase, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        return resp.json();
    }

    // --- Get challenge history ---
    async getHistory() {
        const resp = await fetch(this.apiBase + '?action=get_challenge_history', {
            credentials: 'same-origin'
        });
        return resp.json();
    }

    // --- Render the challenge widget inside a container ---
    renderWidget(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        this.getMonthlyChallenge().then(data => {
            if (!data.success) {
                container.innerHTML = `<p class="aw-challenge-empty">Could not load challenge.</p>`;
                return;
            }

            const { goal, target, progress, completed } = data;
            const percent = Math.min(100, Math.round((progress / target) * 100));

            let html = `
                <div class="aw-challenge-widget">
                    <h4>📖 ${goal}</h4>
                    <div class="aw-challenge-progress">
                        <div class="aw-challenge-bar" style="width: ${percent}%;"></div>
                        <span class="aw-challenge-percent">${percent}%</span>
                    </div>
                    <p class="aw-challenge-stats">${progress} / ${target} pages read</p>
            `;
            if (completed) {
                html += `<p style="color:var(--rose);font-weight:600;">✅ Challenge completed!</p>`;
            } else {
                html += `
                    <button class="aw-challenge-update" onclick="window.readerChallenges.promptUpdate()">
                        📈 Log Progress
                    </button>
                `;
            }
            html += `</div>`;
            container.innerHTML = html;
        });
    }

    // --- Render history ---
    renderHistory(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        this.getHistory().then(data => {
            if (!data.success || !data.history.length) {
                container.innerHTML = `<p style="color:var(--text-light);font-size:0.9rem;">No past challenges.</p>`;
                return;
            }

            let html = `<h5 style="margin:8px 0 4px;">📊 Past Challenges</h5><div style="display:flex;flex-direction:column;gap:6px;">`;
            data.history.forEach(h => {
                const pct = Math.round((h.progress / h.target) * 100);
                html += `
                    <div style="display:flex;justify-content:space-between;font-size:0.85rem;padding:4px 0;border-bottom:1px solid var(--border);">
                        <span>${h.month}/${h.year} – ${h.goal}</span>
                        <span>${h.progress}/${h.target} (${pct}%) ${h.completed ? '✅' : ''}</span>
                    </div>
                `;
            });
            html += `</div>`;
            container.innerHTML = html;
        });
    }

    // --- Prompt user for pages read and update ---
    promptUpdate() {
        const pages = prompt('How many pages did you read today?', '1');
        if (pages === null) return; // cancelled
        const num = parseInt(pages);
        if (isNaN(num) || num < 1) {
            alert('Please enter a positive number.');
            return;
        }
        this.updateProgress(num).then(data => {
            if (data.success) {
                alert('✅ Progress updated!');
                this.renderWidget('awChallengeWidget');
                this.renderHistory('awChallengeHistory');
            } else {
                alert('❌ Failed to update: ' + (data.error || 'Unknown error.'));
            }
        });
    }
}

// --- Global instance ---
window.readerChallenges = null;

// --- Initialization ---
document.addEventListener('DOMContentLoaded', function() {
    const userId = <?php echo isLoggedIn() ? (int)$_SESSION['user_id'] : 0; ?>;
    if (userId) {
        window.readerChallenges = new ReaderChallenges(userId);
    }

    // Sidebar button to open challenge modal
    const challengeBtn = document.getElementById('challengeBtn');
    if (challengeBtn) {
        challengeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openChallengeModal();
        });
    }
});

// --- Modal management ---
function openChallengeModal() {
    const modal = document.getElementById('awChallengeModal');
    if (!modal) return;
    modal.classList.add('visible');
    document.getElementById('overlay')?.classList.add('active');

    // Render widget and history
    if (window.readerChallenges) {
        window.readerChallenges.renderWidget('awChallengeWidget');
        window.readerChallenges.renderHistory('awChallengeHistory');
    }
}

function closeChallengeModal() {
    const modal = document.getElementById('awChallengeModal');
    if (modal) modal.classList.remove('visible');
    document.getElementById('overlay')?.classList.remove('active');
}

// --- Expose promptUpdate globally for the button ---
window.updateChallengeProgress = function() {
    if (window.readerChallenges) {
        window.readerChallenges.promptUpdate();
    }
};
</script>