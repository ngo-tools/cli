import { resolve, dirname } from 'node:path';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const viewsDir = resolve(__dirname, '..', 'views');

export async function uiRoutes(app) {
  // Main iframe page (navigation_entry or contact_tab)
  app.get('/ui', async (req, reply) => {
    const hostUrl = req.query.host_url || 'http://localhost';
    const html = await readFile(resolve(viewsDir, 'app.html'), 'utf-8');
    return reply.type('text/html').send(html.replace('{{HOST_URL}}', hostUrl));
  });
}
