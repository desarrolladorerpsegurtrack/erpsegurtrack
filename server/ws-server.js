import http from 'http';
import { WebSocketServer } from 'ws';

const host = process.env.WS_SERVER_HOST || '127.0.0.1';
const port = Number(process.env.WS_SERVER_PORT || 6001);

const subscriptions = new Map();
const socketTopics = new Map();

const server = http.createServer((req, res) => {
    if (req.method !== 'POST' || req.url !== '/publish') {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Not found' }));
        return;
    }

    let body = '';
    req.on('data', chunk => {
        body += chunk.toString();
    });

    req.on('end', () => {
        try {
            const payload = JSON.parse(body);
            const resource = payload.resource || (payload.clienteId ? 'clientes' : null);
            const id = payload.id || payload.clienteId || '*';

            if (!payload.type || !resource || !id) {
                throw new Error('Invalid payload');
            }

            const message = {
                type: payload.type,
                resource,
                id,
                usuario: payload.usuario || 'anonimo',
                action: payload.action || 'unknown',
                expiresAt: payload.expiresAt || null,
                meta: payload.meta || null,
            };

            broadcastToTopic(topicKey(resource, id), message);

            if (id !== '*') {
                broadcastToTopic(topicKey(resource, '*'), message);
            }

            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ success: true }));
        } catch (error) {
            res.writeHead(400, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ success: false, error: error.message }));
        }
    });
});

const wss = new WebSocketServer({ server });

function topicKey(resource, id) {
    return `${resource}:${id}`;
}

function subscribe(ws, resource, id) {
    const key = topicKey(resource, id);
    const current = subscriptions.get(key) || new Set();
    current.add(ws);
    subscriptions.set(key, current);

    const topics = socketTopics.get(ws) || new Set();
    topics.add(key);
    socketTopics.set(ws, topics);
}

function unsubscribe(ws, resource, id) {
    const key = topicKey(resource, id);
    const topics = socketTopics.get(ws);
    if (topics) {
        topics.delete(key);
        if (topics.size === 0) {
            socketTopics.delete(ws);
        }
    }

    const current = subscriptions.get(key);
    if (current) {
        current.delete(ws);
        if (current.size === 0) {
            subscriptions.delete(key);
        }
    }
}

function cleanupSocket(ws) {
    const topics = socketTopics.get(ws);
    if (!topics) {
        return;
    }

    for (const key of topics) {
        const current = subscriptions.get(key);
        if (current) {
            current.delete(ws);
            if (current.size === 0) {
                subscriptions.delete(key);
            }
        }
    }

    socketTopics.delete(ws);
}

function broadcastToTopic(key, message) {
    const sockets = subscriptions.get(key);
    if (!sockets) {
        return;
    }

    const payload = JSON.stringify(message);
    for (const ws of sockets) {
        if (ws.readyState === ws.OPEN) {
            ws.send(payload);
        }
    }
}

wss.on('connection', (ws) => {
    ws.on('message', (message) => {
        try {
            const payload = JSON.parse(message.toString());
            const resource = payload.resource || (payload.clienteId ? 'clientes' : null);
            const id = payload.id || payload.clienteId;

            if (!resource || !id || typeof resource !== 'string' || typeof id !== 'string') {
                return;
            }

            if (payload.type === 'subscribe') {
                subscribe(ws, resource, id);
            }

            if (payload.type === 'unsubscribe') {
                unsubscribe(ws, resource, id);
            }
        } catch (error) {
            console.warn('WebSocket invalid message:', error.message);
        }
    });

    ws.on('close', () => {
        cleanupSocket(ws);
    });
});

server.listen(port, host, () => {
    // eslint-disable-next-line no-console
    console.log(`WebSocket server listening on ws://${host}:${port}`);
});
