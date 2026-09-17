(() => {
  const toast = document.getElementById('toast');
  const showToast = (message) => {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
  };

  const enableBtn = document.getElementById('enableNotifications');
  if (enableBtn && 'Notification' in window) {
    enableBtn.addEventListener('click', async () => {
      const permission = await Notification.requestPermission();
      showToast(permission === 'granted' ? 'Notifications enabled.' : 'Notification permission was not granted.');
    });
  }

  const postAction = async (payload) => {
    const body = new URLSearchParams(payload);
    const res = await fetch('api_action.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body });
    if (!res.ok) throw new Error('Request failed');
    return res.json();
  };

  const openLinksList = (urls) => {
    if (!Array.isArray(urls) || urls.length === 0) return;

    let blockedCount = 0;
    urls.forEach((url) => {
      try {
        const win = window.open(url, '_blank');
        if (!win || win.closed || typeof win.closed === 'undefined') {
          blockedCount++;
        }
      } catch (err) {
        blockedCount++;
      }
    });

    if (blockedCount > 0) {
      const modal = document.getElementById('popupModal');
      if (modal) {
        modal.classList.add('show');
      } else {
        showToast('⚠️ Pop-up blocked! ব্রাউজারের URL বার থেকে "Always allow pop-ups" অন করুন।');
      }
    }
  };

  const popupModal = document.getElementById('popupModal');
  const closePopupModal = document.getElementById('closePopupModal');
  const popupModalOk = document.getElementById('popupModalOk');
  if (popupModal) {
    const hideModal = () => popupModal.classList.remove('show');
    if (closePopupModal) closePopupModal.addEventListener('click', hideModal);
    if (popupModalOk) popupModalOk.addEventListener('click', hideModal);
    popupModal.addEventListener('click', (e) => {
      if (e.target === popupModal) hideModal();
    });
  }

  document.addEventListener('click', async (e) => {
    const openUnseen = e.target.closest('.open-unseen-post');
    const markSeen = e.target.closest('.mark-post-seen');
    if (openUnseen || markSeen) {
      const btn = openUnseen || markSeen;
      const postId = btn.dataset.postId;
      if (postId) {
        const card = document.getElementById(`unseen-post-${postId}`);
        if (card) {
          card.classList.add('fade-out');
          setTimeout(() => {
            card.remove();
            const remaining = document.querySelectorAll('.latest-post-card:not(.fade-out)').length;
            if (typeof updateBadgeAndEmptyState === 'function') {
              updateBadgeAndEmptyState(remaining);
            }
          }, 220);
        }

        try {
          const body = new URLSearchParams({ post_id: postId });
          fetch('api_latest_posts.php?action=mark_seen', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
          });
          if (markSeen) {
            showToast('পোস্টটি দেখা হয়েছে হিসেবে চিহ্নিত করা হয়েছে।');
          }
        } catch (err) {
          console.error('Failed to mark post seen:', err);
        }
      }
      if (markSeen) return;
    }

    const openAllCard = e.target.closest('.open-all-card-links');
    if (openAllCard) {
      try {
        const urls = JSON.parse(openAllCard.dataset.urls || '[]');
        openLinksList(urls);
      } catch (err) {
        console.error(err);
      }
      return;
    }

    const toggle = e.target.closest('.action-toggle');
    const markAll = e.target.closest('.mark-all');
    const snooze = e.target.closest('.snooze');
    const skip = e.target.closest('.skip');
    try {
      if (toggle) {
        await postAction({ task_id: toggle.dataset.task, type: toggle.dataset.action });
        location.reload();
      } else if (markAll) {
        await postAction({ task_id: markAll.dataset.task, type: 'all_done' });
        location.reload();
      } else if (snooze) {
        await postAction({ task_id: snooze.dataset.task, type: 'snooze', minutes: snooze.dataset.minutes });
        location.reload();
      } else if (skip) {
        await postAction({ task_id: skip.dataset.task, type: 'skip' });
        location.reload();
      }
    } catch (err) {
      showToast('Could not save action.');
    }
  });

  const searchInput = document.getElementById('dashboardSearch');
  const emptySearchState = document.getElementById('searchEmptyState');

  if (searchInput) {
    const doFilter = () => {
      const q = (searchInput.value || '').trim().toLowerCase();
      const cards = document.querySelectorAll('.task-card');
      let visibleCount = 0;

      cards.forEach((card) => {
        const brandName = (card.getAttribute('data-name') || card.querySelector('h3')?.textContent || '').toLowerCase().trim();
        const match = !q || brandName.includes(q);

        if (match) {
          card.classList.remove('is-hidden');
          card.style.display = '';
          visibleCount++;
        } else {
          card.classList.add('is-hidden');
          card.style.display = 'none';
        }
      });

      if (emptySearchState) {
        emptySearchState.style.display = (visibleCount === 0 && q) ? 'block' : 'none';
      }
    };

    try {
      const savedQuery = sessionStorage.getItem('dashboard_search') || '';
      if (savedQuery) {
        searchInput.value = savedQuery;
        doFilter();
      }
    } catch (err) {}

    ['input', 'keyup', 'change', 'search'].forEach((evt) => {
      searchInput.addEventListener(evt, () => {
        try {
          sessionStorage.setItem('dashboard_search', searchInput.value);
        } catch (err) {}
        doFilter();
      });
    });

    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') e.preventDefault();
    });
  }

  const nextReminderCard = document.getElementById('nextReminderCard');
  const nextReminderValue = document.getElementById('nextReminderValue');
  const nextReminderSub = document.getElementById('nextReminderSub');
  const lastReminderValue = document.getElementById('lastReminderValue');
  const lastReminderSub = document.getElementById('lastReminderSub');

  if (nextReminderCard && nextReminderValue) {
    let targetTs = parseInt(nextReminderCard.dataset.targetTs || '0', 10);
    const status = nextReminderCard.dataset.status || '';

    const updateTimer = () => {
      if (!targetTs || status === 'completed' || status === 'window_closed') return;

      const now = Math.floor(Date.now() / 1000);
      const diff = targetTs - now;

      if (diff <= 0) {
        nextReminderValue.textContent = 'Due Now ⚡';
        if (nextReminderSub && status === 'countdown') {
          nextReminderSub.textContent = 'Queue ready for engagement';
        }
      } else {
        const d = new Date(targetTs * 1000);
        const hours = d.getHours();
        const minutes = d.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        const formattedHours = hours % 12 || 12;
        const formattedMins = minutes < 10 ? '0' + minutes : minutes;
        const timeStr = `${formattedHours}:${formattedMins} ${ampm}`;

        const mins = Math.floor(diff / 60);
        const secs = diff % 60;

        if (mins > 60) {
          const hrs = Math.floor(mins / 60);
          const remMins = mins % 60;
          nextReminderValue.textContent = `In ${hrs}h ${remMins}m (${timeStr})`;
        } else if (mins > 0) {
          nextReminderValue.textContent = `In ${mins}m ${secs < 10 ? '0' + secs : secs}s (${timeStr})`;
        } else {
          nextReminderValue.textContent = `In ${secs}s (${timeStr})`;
        }
      }
    };

    updateTimer();
    setInterval(updateTimer, 1000);

    window.updateReminderCards = (data) => {
      if (!data) return;
      if (data.last_reminded_name && lastReminderValue) {
        lastReminderValue.textContent = `${data.last_reminded_time} · ${data.last_reminded_name}`;
        if (lastReminderSub) lastReminderSub.textContent = 'Just now';
      }
      if (data.next_target_ts) {
        targetTs = parseInt(data.next_target_ts, 10);
        nextReminderCard.dataset.targetTs = targetTs;
        nextReminderCard.dataset.status = 'countdown';
        updateTimer();
      }
    };
  }

  const checkFeedsBtn = document.getElementById('checkFeedsBtn');
  if (checkFeedsBtn) {
    checkFeedsBtn.addEventListener('click', async () => {
      const origText = checkFeedsBtn.innerHTML;
      checkFeedsBtn.disabled = true;
      checkFeedsBtn.innerHTML = '⏳ Scanning Feeds...';
      try {
        const res = await fetch('fetch_posts.php?force=1', { cache: 'no-store' });
        const data = await res.json();
        const count = data?.result?.new_posts ?? 0;
        const checked = data?.result?.checked_brands ?? 0;
        if (count > 0) {
          showToast(`🎉 ${count} টি নতুন পোস্ট পাওয়া গেছে!`);
          setTimeout(() => location.reload(), 800);
        } else {
          showToast(`স্ক্যান সম্পন্ন: ${checked} টি ব্র্যান্ড চেক করা হয়েছে, কোনো নতুন পোস্ট নেই।`);
        }
      } catch (err) {
        showToast('ফিড স্ক্যান করতে সমস্যা হয়েছে।');
      } finally {
        checkFeedsBtn.disabled = false;
        checkFeedsBtn.innerHTML = origText;
      }
    });
  }

  // --- LATEST POSTS SECTION LOGIC ---
  const refreshLatestPostsBtn = document.getElementById('refreshLatestPostsBtn');
  const markAllPostsSeenBtn = document.getElementById('markAllPostsSeenBtn');
  const latestPostsGrid = document.getElementById('latestPostsGrid');
  const latestPostsEmptyState = document.getElementById('latestPostsEmptyState');
  const unseenPostsBadge = document.getElementById('unseenPostsBadge');

  const escapeHtml = (str) => {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  const updateBadgeAndEmptyState = (count) => {
    if (unseenPostsBadge) unseenPostsBadge.textContent = count;
    if (count === 0) {
      if (latestPostsGrid) latestPostsGrid.style.display = 'none';
      if (latestPostsEmptyState) latestPostsEmptyState.style.display = 'block';
      if (markAllPostsSeenBtn) markAllPostsSeenBtn.style.display = 'none';
    } else {
      if (latestPostsGrid) latestPostsGrid.style.display = 'grid';
      if (latestPostsEmptyState) latestPostsEmptyState.style.display = 'none';
      if (markAllPostsSeenBtn) markAllPostsSeenBtn.style.display = 'inline-flex';
    }
  };

  const renderLatestPosts = (posts) => {
    if (!latestPostsGrid) return;
    if (!posts || posts.length === 0) {
      latestPostsGrid.innerHTML = '';
      updateBadgeAndEmptyState(0);
      return;
    }

    latestPostsGrid.innerHTML = posts.map((p) => {
      const platformKey = (p.platform || 'social').toLowerCase().replace(/[^a-z0-9]/g, '');
      return `
        <div class="latest-post-card" id="unseen-post-${p.id}" data-post-id="${p.id}">
          <div>
            <div class="latest-post-header">
              <div class="latest-post-brand">
                <span>${escapeHtml(p.brand_name)}</span>
                <span class="platform-pill platform-${escapeHtml(platformKey)}">${escapeHtml(p.platform)}</span>
              </div>
              <span class="latest-post-time">${escapeHtml(p.relative_time || '')}</span>
            </div>
            <h4 class="latest-post-title">
              <a href="${escapeHtml(p.post_url)}" target="_blank" rel="noopener" class="open-unseen-post" data-post-id="${p.id}">
                ${escapeHtml(p.title || 'New Post')}
              </a>
            </h4>
            ${p.snippet ? `<p class="latest-post-snippet">${escapeHtml(p.snippet)}</p>` : ''}
          </div>
          <div class="latest-post-actions">
            <a href="${escapeHtml(p.post_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-primary open-unseen-post" data-post-id="${p.id}">
              ⚡ Open Post ↗
            </a>
            <button type="button" class="btn btn-sm btn-ghost mark-post-seen" data-post-id="${p.id}" title="Mark as seen without opening">
              ✓ Mark Seen
            </button>
          </div>
        </div>
      `;
    }).join('');

    updateBadgeAndEmptyState(posts.length);
  };

  if (refreshLatestPostsBtn) {
    refreshLatestPostsBtn.addEventListener('click', async () => {
      const origText = refreshLatestPostsBtn.innerHTML;
      refreshLatestPostsBtn.disabled = true;
      refreshLatestPostsBtn.innerHTML = '⏳ Scanning...';
      try {
        const res = await fetch('api_latest_posts.php?action=refresh', { method: 'POST' });
        const data = await res.json();
        if (data.ok) {
          renderLatestPosts(data.posts);
          const newCount = data.new_posts || 0;
          if (newCount > 0) {
            showToast(`🎉 ${newCount} টি নতুন পোস্ট যুক্ত হয়েছে!`);
          } else {
            showToast('ফিড রিফ্রেশ সম্পন্ন, সব পোস্ট আপ-টু-ডেট আছে।');
          }
        } else {
          showToast('ফিড রিফ্রেশ করতে সমস্যা হয়েছে।');
        }
      } catch (err) {
        showToast('ফিড রিফ্রেশ করতে সমস্যা হয়েছে।');
      } finally {
        refreshLatestPostsBtn.disabled = false;
        refreshLatestPostsBtn.innerHTML = origText;
      }
    });
  }

  if (markAllPostsSeenBtn) {
    markAllPostsSeenBtn.addEventListener('click', async () => {
      if (!confirm('আপনি কি এই তালিকায় থাকা সব পোস্ট "দেখা হয়েছে" হিসেবে চিহ্নিত করতে চান?')) {
        return;
      }
      try {
        const res = await fetch('api_latest_posts.php?action=mark_all_seen', { method: 'POST' });
        const data = await res.json();
        if (data.ok) {
          renderLatestPosts([]);
          showToast('সবগুলো পোস্ট দেখা হয়েছে হিসেবে মার্ক করা হয়েছে!');
        }
      } catch (err) {
        showToast('অ্যাকশন সম্পন্ন করা যায়নি।');
      }
    });
  }

  const pollSeconds = parseInt(document.body.dataset.pollSeconds || '60', 10);
  let lastNotifiedKey = null;

  async function checkDue() {
    try {
      const res = await fetch('api_due.php', { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok || !data.due) return;
      const due = data.due;

      if (window.updateReminderCards) {
        window.updateReminderCards(data);
      }

      // --- BATCH LATEST POSTS NOTIFICATION ---
      if (data.type === 'batch_latest_posts') {
        const notifyKey = `batch-posts-${due.post_ids ? due.post_ids.slice().sort().join('-') : Date.now()}`;
        if (lastNotifiedKey === notifyKey) return;
        lastNotifiedKey = notifyKey;

        const titleText = due.title || '📢 নতুন পোস্ট পাওয়া গেছে!';
        const bodyText = due.body || 'ক্লিক করে সব নতুন পোস্ট দেখুন ও Seen মার্ক করুন';
        showToast(titleText);

        if ('Notification' in window && Notification.permission === 'granted') {
          const notification = new Notification(titleText, {
            body: bodyText,
            tag: notifyKey,
            requireInteraction: true
          });

          notification.onclick = async () => {
            window.focus();
            notification.close();

            // 1. Open all latest post links simultaneously in new tabs
            if (Array.isArray(due.urls) && due.urls.length > 0) {
              openLinksList(due.urls);
            }

            // 2. Mark all these posts as seen and engaged in DB
            if (Array.isArray(due.post_ids) && due.post_ids.length > 0) {
              try {
                await fetch('api_latest_posts.php?action=mark_seen_batch', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ post_ids: due.post_ids })
                });
                showToast(`সবগুলো পোস্ট (${due.count}টি) ওপেন করা হয়েছে ও Seen মার্ক করা হয়েছে!`);
                setTimeout(() => {
                  location.reload();
                }, 800);
              } catch (err) {
                console.error('Failed to mark batch seen:', err);
              }
            }
          };
        }
        return;
      }

      // --- ROUTINE PER-SOCIAL-LINK REMINDER ---
      const notifyKey = `social-${due.social_link_id || due.id}-${Math.floor(Date.now() / 60000)}`;
      if (lastNotifiedKey === notifyKey) return;
      lastNotifiedKey = notifyKey;

      const platformLabel = due.platform ? ` (${due.platform})` : '';
      const titleText = `🔔 Engagement Reminder: ${due.name}${platformLabel}`;
      const bodyText = `${due.name}-এর ${due.platform || 'Social'} পেজে লাইক/কমেন্ট করুন।\n👉 ক্লিক করে পেজ ওপেন করুন (Mark Done হবে)`;
      showToast(titleText);

      if ('Notification' in window && Notification.permission === 'granted') {
        const notification = new Notification(titleText, {
          body: bodyText,
          tag: notifyKey,
          requireInteraction: true
        });

        notification.onclick = async () => {
          window.focus();
          if (due.open_url) {
            window.open(due.open_url, '_blank');
          }
          const card = document.getElementById(`task-${due.id}`);
          if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
          notification.close();

          try {
            await postAction({ task_id: due.id, type: 'all_done' });
            showToast(`পেজ ওপেন হয়েছে ও ${due.name} মার্ক করা হয়েছে!`);
            setTimeout(() => {
              location.reload();
            }, 600);
          } catch (err) {
            console.error('Failed to mark task all done:', err);
          }
        };
      }
    } catch (err) {
      // Keep polling silently. The dashboard itself remains usable.
    }
  }

  if (document.body.dataset.pollSeconds) {
    checkDue();
    setInterval(checkDue, Math.max(30, pollSeconds) * 1000);
  }
})();
