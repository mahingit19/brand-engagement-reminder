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

  const pollSeconds = parseInt(document.body.dataset.pollSeconds || '60', 10);
  let lastNotifiedKey = null;

  async function checkDue() {
    try {
      const res = await fetch('api_due.php', { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok || !data.due) return;
      const due = data.due;
      const isNewPost = data.type === 'new_post';
      const notifyKey = isNewPost ? `post-${due.post_id}` : `task-${due.id}`;

      if (window.updateReminderCards) {
        window.updateReminderCards(data);
      }

      if (lastNotifiedKey === notifyKey) return;
      lastNotifiedKey = notifyKey;

      if (isNewPost) {
        const platformLabel = due.platform ? ` (${due.platform})` : '';
        showToast(`📢 নতুন পোস্ট: ${due.name}${platformLabel} - ${due.post_title}`);

        if ('Notification' in window && Notification.permission === 'granted') {
          const notification = new Notification(`📢 নতুন পোস্ট: ${due.name}${platformLabel}`, {
            body: `${due.post_title}\n👉 ক্লিক করে সরাসরি পোস্ট দেখুন (Mark Done হবে)`,
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
              await postAction({ task_id: due.id, post_id: due.post_id, type: 'all_done' });
              showToast(`নতুন পোস্ট ওপেন হয়েছে ও ${due.name} মার্ক করা হয়েছে!`);
              setTimeout(() => {
                location.reload();
              }, 600);
            } catch (err) {
              console.error('Failed to mark task all done:', err);
            }
          };
        }
      } else {
        showToast(`Reminder: ${due.name} needs engagement.`);

        if ('Notification' in window && Notification.permission === 'granted') {
          const notification = new Notification('Social engagement reminder', {
            body: `${due.name}: Like, comment and share if appropriate.`,
            tag: notifyKey,
            requireInteraction: true
          });

          notification.onclick = async () => {
            window.focus();
            if (Array.isArray(due.links) && due.links.length > 0) {
              openLinksList(due.links);
            } else if (due.open_url) {
              openLinksList([due.open_url]);
            }
            const card = document.getElementById(`task-${due.id}`);
            if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            notification.close();

            try {
              await postAction({ task_id: due.id, type: 'all_done' });
              showToast(`Marked ${due.name} as All Done.`);
              setTimeout(() => {
                location.reload();
              }, 600);
            } catch (err) {
              console.error('Failed to mark task all done:', err);
            }
          };
        }
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
