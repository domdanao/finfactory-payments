// A webhook receiver with only Node.js built-ins.
//
//   FINFACTORY_WEBHOOK_SECRET=whsec_... node examples/webhook-server.mjs
//
// Expose it over https (for example with a tunnel) and register the URL as a webhook endpoint.
import { createServer } from "node:http";
import { Webhooks, WebhookSignatureError } from "../dist/index.js";

const secret = process.env.FINFACTORY_WEBHOOK_SECRET;
const seen = new Set(); // use your database in production: deliveries can repeat

createServer(async (req, res) => {
  if (req.method !== "POST" || req.url !== "/webhooks") return res.writeHead(404).end();

  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const body = Buffer.concat(chunks);

  let event;
  try {
    event = await Webhooks.constructEvent(body, req.headers["webhook-signature"], secret);
  } catch (e) {
    if (e instanceof WebhookSignatureError) return res.writeHead(400).end(e.message);
    throw e;
  }

  res.writeHead(200).end(); // answer within 10 seconds; do the work after
  if (seen.has(event.id)) return;
  seen.add(event.id);

  switch (event.type) {
    case "payment_intent.succeeded":
      console.log("paid:", event.data.object.id, event.data.object.amount);
      break;
    case "payment_intent.payment_failed":
      console.log("failed:", event.data.object.id, event.data.object.last_payment_error?.code);
      break;
    default:
      console.log("event:", event.type);
  }
}).listen(3000, () => console.log("Listening on http://localhost:3000/webhooks"));
