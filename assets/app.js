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

  document.addEventListener('click', async (e) => {
    const openAllCard = e.target.closest('.open-all-card-links');
    if (openAllCard) {
      try {
        const urls = JSON.parse(openAllCard.dataset.urls || '[]');
        urls.forEach(url => window.open(url, '_blank', 'noopener'));
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

  const pollSeconds = parseInt(document.body.dataset.pollSeconds || '60', 10);
  let lastNotifiedTask = null;

  async function checkDue() {
    try {
      const res = await fetch('api_due.php', { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok || !data.due) return;
      const due = data.due;
      if (String(lastNotifiedTask) === String(due.id)) return;
      lastNotifiedTask = due.id;

      showToast(`Reminder: ${due.name} needs engagement.`);

      if ('Notification' in window && Notification.permission === 'granted') {
        const notification = new Notification('Social engagement reminder', {
          body: `${due.name}: Like, comment and share if appropriate.`,
          tag: `engagement-${due.id}`,
          requireInteraction: true
        });
        notification.onclick = () => {
          window.focus();
          if (Array.isArray(due.links) && due.links.length > 0) {
            due.links.forEach((url) => {
              window.open(url, '_blank', 'noopener');
            });
          } else if (due.open_url) {
            window.open(due.open_url, '_blank', 'noopener');
          }
          const card = document.getElementById(`task-${due.id}`);
          if (card) card.scrollIntoView({behavior:'smooth', block:'center'});
          notification.close();
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
