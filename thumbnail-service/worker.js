import 'dotenv/config.js';
import { createClient } from 'redis';
import { S3Client } from '@aws-sdk/client-s3';

const redis = createClient({
  url: process.env.REDIS_URL || 'redis://redis:6379',
});

redis.on('error', (err) => console.error('Redis error:', err));
redis.on('connect', () => console.log('Connected to Redis'));

await redis.connect();

const s3Client = new S3Client({
  region: 'us-east-1',
  endpoint: process.env.MINIO_ENDPOINT || 'http://minio:9000',
  credentials: {
    accessKeyId: process.env.MINIO_ACCESS_KEY || 'minio',
    secretAccessKey: process.env.MINIO_SECRET_KEY || 'minio123',
  },
  forcePathStyle: true,
});

const QUEUE_NAME = process.env.THUMBNAIL_QUEUE || 'thumbnail:queue';

async function processJob(job) {
  try {
    console.log(`Processing job: ${job}`);
    // TODO: Implement thumbnail processing logic
    console.log(`Job ${job} completed`);
  } catch (error) {
    console.error(`Error processing job: ${error.message}`);
  }
}

async function startWorker() {
  console.log('Thumbnail service worker started');
  console.log(`Listening on queue: ${QUEUE_NAME}`);

  while (true) {
    try {
      const job = await redis.blPop(QUEUE_NAME, 0);
      if (job) {
        await processJob(job.element);
      }
    } catch (error) {
      console.error('Worker error:', error);
      await new Promise((resolve) => setTimeout(resolve, 5000));
    }
  }
}

startWorker().catch(console.error);

process.on('SIGTERM', async () => {
  console.log('SIGTERM received, closing connections');
  await redis.quit();
  process.exit(0);
});
