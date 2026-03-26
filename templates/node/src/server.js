import 'dotenv/config';
import Fastify from 'fastify';
import cors from '@fastify/cors';
import fastifyStatic from '@fastify/static';
import formbody from '@fastify/formbody';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { webhookRoutes } from './routes/webhooks.js';
import { uiRoutes } from './routes/ui.js';

const __dirname = dirname(fileURLToPath(import.meta.url));

const app = Fastify({ logger: { level: 'info' } });

await app.register(cors, { origin: true });
await app.register(formbody);
await app.register(fastifyStatic, {
  root: resolve(__dirname, 'views'),
  prefix: '/static/',
  decorateReply: false,
});

// Preserve raw body for webhook signature verification
app.addContentTypeParser('application/json', { parseAs: 'string' }, (req, body, done) => {
  req.rawBody = body;
  try {
    done(null, JSON.parse(body));
  } catch (err) {
    done(err);
  }
});

await app.register(webhookRoutes);
await app.register(uiRoutes);

// Well-known manifest (for auto-discovery)
app.get('/.well-known/ngotools.json', async (req, reply) => {
  return reply.sendFile('ngotools.json', resolve(__dirname, '..', '.well-known'));
});

app.get('/health', async () => ({ status: 'ok' }));

const port = parseInt(process.env.PORT || '{{PORT}}', 10);
const host = process.env.HOST || '0.0.0.0';

await app.listen({ port, host });
console.log(`\n  {{NAME}} running on http://localhost:${port}\n`);
