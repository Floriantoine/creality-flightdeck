// K1 Alertes — service worker : reçoit le « réveil » push et affiche la notification
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", e => e.waitUntil(self.clients.claim()));

self.addEventListener("push", e => {
  e.waitUntil((async () => {
    let m = { title: "K1 Max", body: "Nouvelle alerte — ouvre l'app pour les détails", priority: "high" };
    try {
      const sub = await self.registration.pushManager.getSubscription();
      const r = await fetch("latest.php", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ endpoint: sub && sub.endpoint, n: 1 }), cache: "no-store"
      });
      const j = await r.json();
      if (j.messages && j.messages[0]) m = j.messages[0];
    } catch (_) {}
    const urgent = m.priority === "high";
    await self.registration.showNotification(m.title, {
      body: m.body,
      icon: "icon-192.png",
      image: m.image ? new URL(m.image, self.registration.scope).href : undefined,
      badge: "badge-96.png",
      tag: "k1-" + (m.id || Date.now()),
      renotify: true,
      requireInteraction: urgent,
      vibrate: urgent ? [600, 200, 600, 200, 1000] : [200],
      data: { url: "./" }
    });
  })());
});

self.addEventListener("notificationclick", e => {
  e.notification.close();
  e.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const c of all) if ("focus" in c) return c.focus();
    return self.clients.openWindow("./");
  })());
});
