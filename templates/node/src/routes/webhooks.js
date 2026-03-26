import { verifyWebhookSignature } from '../services/webhook-verify.js';

export async function webhookRoutes(app) {
  app.post('/webhooks', async (request, reply) => {
    const signature = request.headers['x-ngotools-signature'];
    const timestamp = request.headers['x-ngotools-timestamp'];
    const event = request.headers['x-ngotools-event'];
    const secret = process.env.NGOTOOLS_WEBHOOK_SECRET;

    if (!secret) {
      app.log.warn('NGOTOOLS_WEBHOOK_SECRET not configured — skipping verification');
    } else {
      const result = verifyWebhookSignature({
        signature,
        timestamp,
        rawBody: request.rawBody,
        secret,
      });

      if (!result.valid) {
        app.log.warn(`Webhook verification failed: ${result.error}`);
        return reply.code(401).send({ error: result.error });
      }
    }

    app.log.info(`Received webhook: ${event}`);
    app.log.info(request.body);

    // TODO: Handle events here
    // switch (event) {
    //   case 'donation.created':
    //     break;
    //   case 'contact.created':
    //     break;
    // }

    return reply.code(200).send({ ok: true });
  });
}
