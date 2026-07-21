const wsHost = import.meta.env.VITE_WS_SERVER_HOST || window.location.hostname;
const wsPort = import.meta.env.VITE_WS_SERVER_PORT || 6001;
const wsScheme = window.location.protocol === 'https:' ? 'wss' : 'ws';
const wsUrl = `${wsScheme}://${wsHost}:${wsPort}`;
const previousRealtimeState = window.ERPRealtime || {};

window.ERPRealtime = {
    ws: previousRealtimeState.ws || null,
    connected: previousRealtimeState.connected || false,
    queue: Array.isArray(previousRealtimeState.queue) ? previousRealtimeState.queue : [],
    subscribedTopics: previousRealtimeState.subscribedTopics instanceof Set ? previousRealtimeState.subscribedTopics : new Set(),
    pageLockBlocked: Boolean(previousRealtimeState.pageLockBlocked),
    currentUser: previousRealtimeState.currentUser || document.querySelector('meta[name="erp-current-user"]')?.content || '',
    currentResource: previousRealtimeState.currentResource || '',
    currentResourceId: previousRealtimeState.currentResourceId || '',
    currentListResource: previousRealtimeState.currentListResource || '',
    listMode: Boolean(previousRealtimeState.listMode),
    lockRefreshTimer: previousRealtimeState.lockRefreshTimer || null,
    suppressUnloadRelease: Boolean(previousRealtimeState.suppressUnloadRelease),

    notify(message, type = 'info') {
        const event = new CustomEvent('erp-realtime-notification', {
            detail: { message, type },
        });
        window.dispatchEvent(event);
        console[type === 'error' ? 'error' : 'info'](`[Realtime] ${message}`);
    },

    disableEditButton() {
        const editButton = document.getElementById('btnEditar');
        if (!editButton) {
            return;
        }
        editButton.disabled = true;
        editButton.classList.add('opacity-70', 'cursor-not-allowed');
    },

    enableEditButton() {
        const editButton = document.getElementById('btnEditar');
        if (!editButton) {
            return;
        }
        editButton.disabled = false;
        editButton.classList.remove('opacity-70', 'cursor-not-allowed');
    },

    connect() {
        if (this.ws) {
            return;
        }

        this.ws = new WebSocket(wsUrl);

        this.ws.addEventListener('open', () => {
            this.connected = true;
            this.notify('Conectado al servidor WebSocket local.', 'info');
            while (this.queue.length > 0) {
                const message = this.queue.shift();
                this.ws.send(JSON.stringify(message));
            }

            for (const topic of this.subscribedTopics) {
                const [resource, id] = topic.split(':');
                this.sendWs({ type: 'subscribe', resource, id });
            }

            this.subscribeUser();
        });

        this.ws.addEventListener('message', (event) => {
            try {
                const payload = JSON.parse(event.data);

                if (payload?.type === 'lock.changed') {
                    if (payload.resource !== this.currentResource || payload.id !== this.currentResourceId) {
                        return;
                    }

                    if (payload.action === 'locked') {
                        if (payload.usuario !== this.currentUser) {
                            this.notify(`Registro ${payload.id} bloqueado por ${payload.usuario}.`, 'error');
                            this.disableEditButton();
                            this.pageLockBlocked = true;
                        }
                    } else if (payload.action === 'released') {
                        this.notify(`Registro ${payload.id} se ha liberado.`, 'info');
                        if (this.pageLockBlocked) {
                            this.enableEditButton();
                            this.pageLockBlocked = false;
                        }
                    }

                    return;
                }


                if (payload?.resource === 'user' && payload?.id === this.currentUser) {
                    this.notify('Tus permisos han sido actualizados. Se recargará la aplicación para aplicar los cambios.', 'info');
                    window.location.href = '/';
                    return;
                }

                // Notificaciones dirigidas al usuario (por ejemplo: nueva gestión asignada, pero enviada globalmente)
                const isNotificationForUser = payload?.type === 'resource.changed' && payload?.resource === 'notification' && payload?.id === this.currentUser;
                // Notificaciones dirigidas a la vista (por ejemplo: nueva gestión asignada enviada a la vista)
                const isNotificationForVista = payload?.type === 'resource.changed' && payload?.resource === 'vista' && payload?.action === 'ticket.assigned';

                if (isNotificationForUser || isNotificationForVista) {
                    const message = (payload.meta && payload.meta.message) ? payload.meta.message : 'Tienes una nueva gestión para atender.';
                    const url = payload.meta && payload.meta.url ? payload.meta.url : null;

                    // Log para depuración rápida en consola
                    console.info('[Realtime] notification payload received:', payload);

                    // Intentar usar plantilla HTML oculta si existe (nueva plantilla `notification-template`)
                    const template = document.getElementById('notification-template');
                    if (template && typeof Toastify === 'function') {
                        try {
                            const wrapper = template.cloneNode(true);
                            // Usar el nodo principal copiado para preservar la estructura completa.
                            const node = wrapper.firstElementChild || wrapper;
                            node.id = '';
                            node.classList.remove('hidden');

                            // Actualizar texto y enlace si existen elementos esperados
                            const textEl = node.querySelector('#notification-template-text');
                            if (textEl) {
                                textEl.textContent = message;
                                textEl.id = '';
                            }
                            const actionEl = node.querySelector('#notification-template-action');
                            if (actionEl) {
                                const defaultUrl = actionEl.dataset.defaultUrl || '/';
                                const targetUrl = url || defaultUrl;
                                actionEl.textContent = 'Atenderlo';
                                actionEl.setAttribute('href', targetUrl);
                                actionEl.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    window.location.href = targetUrl;
                                });
                                actionEl.id = '';
                            }

                            // Mostrar Toastify como sticky (duration -1) y con Close (X)
                            Toastify({
                                node: node,
                                duration: -1,
                                close: true,
                                gravity: 'top',
                                style: {
                                    background: "#ffffff",
                                    color: "#1e293b",
                                },
                                position: 'right',
                                stopOnFocus: true,
                                onClick: function () {},
                            }).showToast();
                        } catch (e) {
                            console.warn('[Realtime] error showing template notification', e);
                        }
                        return;
                    }

                    // Fallback textual si no hay plantilla o Toastify
                    if (typeof Toastify === 'function') {
                        Toastify({
                            text: message,
                            duration: -1,
                            close: true,
                            gravity: 'top',
                            position: 'right',
                            stopOnFocus: true,
                            onClick: function () {
                                if (url) {
                                    window.location.href = url;
                                }
                            }
                        }).showToast();
                    } else {
                        // Último recurso: alert/confirm
                        if (url) {
                            if (confirm(message + '\nAbrir la gestión ahora?')) {
                                window.location.href = url;
                            }
                        } else {
                            alert(message);
                        }
                    }

                    return;
                }

                if (payload?.type === 'resource.changed') {
                    if (payload.resource === this.currentListResource && this.listMode) {
                        if (payload.usuario === this.currentUser && payload.action === 'attend') {
                            return;
                        }

                        this.notify(`Actualización en tiempo real: ${payload.action} en ${payload.resource}.`, 'info');
                        if (typeof window.ERPListRefresh === 'function') {
                            window.ERPListRefresh();
                        } else {
                            window.location.reload();
                        }
                    }

                    if (payload.resource === this.currentResource && payload.id === this.currentResourceId && payload.usuario !== this.currentUser) {
                        if (payload.action === 'deleted') {
                            this.notify(`Este registro ha sido eliminado por ${payload.usuario}.`, 'error');
                        } else if (payload.action === 'updated') {
                            this.notify(`Este registro ha sido actualizado por ${payload.usuario}.`, 'info');
                        }
                    }
                }
            } catch (error) {
                console.warn('[Realtime] Error parsing websocket message:', error);
            }
        });

        this.ws.addEventListener('close', () => {
            this.connected = false;
            this.ws = null;
            this.notify('Conexión WebSocket cerrada, reintentando...', 'error');
            setTimeout(() => this.connect(), 2000);
        });

        this.ws.addEventListener('error', (event) => {
            console.warn('[Realtime] WebSocket error', event);
        });
    },

    sendWs(payload) {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            this.queue.push(payload);
            if (!this.ws) {
                this.connect();
            }
            return;
        }

        this.ws.send(JSON.stringify(payload));
    },

    startLockHeartbeat(resource, id) {
        if (this.lockRefreshTimer) {
            clearInterval(this.lockRefreshTimer);
        }

        this.lockRefreshTimer = setInterval(() => {
            if (!this.pageLockBlocked && this.currentResource === resource && this.currentResourceId === id) {
                this.acquireLock(resource, id);
            }
        }, 45000);
    },

    stopLockHeartbeat() {
        if (!this.lockRefreshTimer) {
            return;
        }

        clearInterval(this.lockRefreshTimer);
        this.lockRefreshTimer = null;
    },

    async acquireLock(resource, id) {
        if (!window.axios) {
            this.notify('No se pudo inicializar el cliente HTTP para bloqueo en tiempo real.', 'error');
            return false;
        }

        try {
            const response = await window.axios.post(`/locks/${resource}/${encodeURIComponent(id)}/acquire`);
            const payload = response.data;
            if (!payload.success) {
                this.notify(`No se pudo bloquear el registro ${id}. ${payload.message || ''}`, 'error');
                this.disableEditButton();
                this.pageLockBlocked = true;
                return false;
            }
            this.pageLockBlocked = false;
            this.notify(`Bloqueo aplicado para el registro ${id}.`, 'info');
            return true;
        } catch (error) {
            const conflict = error?.response?.status === 409;
            if (conflict) {
                this.disableEditButton();
                this.pageLockBlocked = true;
            }
            this.notify(`Error al solicitar el bloqueo del registro ${id}.`, 'error');
            return false;
        }
    },

    async releaseLock(resource, id) {
        this.stopLockHeartbeat();

        if (!window.axios) {
            this.leaveLock(resource, id);
            return;
        }

        try {
            await window.axios.post(`/locks/${resource}/${encodeURIComponent(id)}/release`);
            this.notify(`Bloqueo liberado para el registro ${id}.`, 'info');
        } catch (error) {
            console.warn('[Realtime] Error al liberar bloqueo:', error);
        } finally {
            this.leaveLock(resource, id);
        }
    },

    subscribeLock(resource, id) {
        const topic = `${resource}:${id}`;
        if (this.subscribedTopics.has(topic)) {
            return;
        }

        this.subscribedTopics.add(topic);
        this.sendWs({ type: 'subscribe', resource, id });
        if (!this.pageLockBlocked) {
            this.acquireLock(resource, id);
            this.startLockHeartbeat(resource, id);
        }
    },

    subscribeResource(resource, id = '*') {
        const topic = `${resource}:${id}`;
        if (this.subscribedTopics.has(topic)) {
            return;
        }

        this.subscribedTopics.add(topic);
        this.sendWs({ type: 'subscribe', resource, id });
    },

    subscribeUser() {
        if (!this.currentUser) {
            return;
        }

        this.subscribeResource('user', this.currentUser);
        // Suscribirse además a notificaciones dirigidas al usuario
        this.subscribeResource('notification', this.currentUser);

        // Suscribirse a las vistas que el usuario tiene permitidas
        const allowedVistasMeta = document.querySelector('meta[name="erp-allowed-vistas"]');
        if (allowedVistasMeta) {
            try {
                const vistas = JSON.parse(allowedVistasMeta.content);
                vistas.forEach(vistaId => {
                    this.subscribeResource('vista', vistaId.toString());
                });
            } catch (e) {
                console.warn('[Realtime] Error al parsear erp-allowed-vistas:', e);
            }
        }
    },

    leaveLock(resource, id) {
        this.stopLockHeartbeat();

        const topic = `${resource}:${id}`;
        if (!this.subscribedTopics.has(topic)) {
            return;
        }

        this.subscribedTopics.delete(topic);
        this.sendWs({ type: 'unsubscribe', resource, id });
    },
};

window.addEventListener('DOMContentLoaded', () => {
    if (window.ERPRealtime.currentUser) {
        window.ERPRealtime.connect();
        window.ERPRealtime.subscribeUser();
    }

    const resourceInput = document.querySelector('#erp-lock-resource');
    const idInput = document.querySelector('#erp-lock-id');
    if (resourceInput && idInput) {
        const resource = resourceInput.value;
        const id = idInput.value;
        if (resource && id) {
            window.ERPRealtime.currentResource = resource;
            window.ERPRealtime.currentResourceId = id;
            window.ERPRealtime.subscribeLock(resource, id);

            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }
                if (!form.hasAttribute('data-lock-action')) {
                    return;
                }

                window.ERPRealtime.suppressUnloadRelease = true;
                setTimeout(() => {
                    window.ERPRealtime.suppressUnloadRelease = false;
                }, 3000);
            });

            const form = document.getElementById('main-crud-form');
            if (form) {
                form.addEventListener('submit', (event) => {
                    if (form.dataset.lockValidated === '1') {
                        form.dataset.lockValidated = '0';
                        return;
                    }

                    event.preventDefault();

                    window.ERPRealtime.acquireLock(resource, id).then((granted) => {
                        if (!granted) {
                            window.ERPRealtime.notify('No se puede guardar porque otro usuario mantiene el bloqueo.', 'error');
                            return;
                        }

                        form.dataset.lockValidated = '1';

                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                            return;
                        }

                        form.submit();
                    });
                });
            }

            window.addEventListener('beforeunload', () => {
                if (window.ERPRealtime.suppressUnloadRelease) {
                    return;
                }
                window.ERPRealtime.releaseLock(resource, id);
            });
        }
    }

    const listResourceInput = document.querySelector('#erp-list-resource');
    if (listResourceInput) {
        const listResource = listResourceInput.value;
        if (listResource) {
            window.ERPRealtime.currentListResource = listResource;
            window.ERPRealtime.listMode = true;
            window.ERPRealtime.subscribeResource(listResource, '*');
        }
    }
});
