# System Architecture

## System Overview

AiClip is a modular application with a dedicated media worker boundary, designed to transform long-form video into short-form social content, generate AI images, and publish across multiple social platforms. The architecture separates concerns across four primary boundaries: a React web frontend, a Laravel API backend, a Python media worker, and infrastructure components. This separation enables clear ownership boundaries and technology selection per boundary.

The platform follows a request-response pattern for user interactions and an event-driven pattern for media processing. User actions trigger API calls that may enqueue background jobs for heavyweight processing like transcription, scene detection, and image generation. Results are stored in object storage and metadata in PostgreSQL, with the frontend polling or using websockets for status updates.

## Service Decomposition

### 1. React Web Frontend

**Responsibility**: User interface for all platform interactions

**Technology Stack**:
- React 19.2.8 with TypeScript 6.0.2
- Vite 8.2.2 for build tooling
- Jest + React Testing Library for testing
- Responsive CSS framework (Tailwind CSS or similar)

**Key Capabilities**:
- Authentication flows (login, registration, email verification)
- Project dashboard and management
- Video upload with progress tracking
- Clip review and editing interface
- Image generation interface with prompt configuration
- Social account connection management
- Publishing workflow with platform selection
- Media library browsing and organization

**Boundaries**:
- Communicates with Laravel API via REST
- No direct database access
- No file system access
- Stateless, can be served from CDN

### 2. Laravel API Backend

**Responsibility**: Application logic, authentication, authorization, data persistence

**Technology Stack**:
- Laravel 13 with PHP 8.3.6
- PostgreSQL 16 for primary data store
- Laravel Queues for job dispatching
- Laravel Sanctum for API authentication

**Key Capabilities**:
- User authentication and authorization
- Project CRUD operations
- Media metadata management
- Job orchestration for media processing
- Social OAuth token management
- Publishing coordination
- API rate limiting and validation

**Boundaries**:
- Authoritative source for application state
- Dequeues media processing jobs to worker
- Manages social OAuth tokens encrypted at rest
- Never exposes refresh tokens to frontend
- Enforces user isolation on all data access

### 3. Python Media Worker

**Responsibility**: Heavy ML/media processing outside PHP runtime

**Queue Boundary**: Laravel dispatches jobs to a queue (Redis/SQS). The Python worker consumes from the same queue using a compatible client library (rq for Redis, boto3 for SQS), not Laravel-native serialized jobs. This ensures the worker does not depend on Laravel's internal serialization format.

**Technology Stack**:
- Python 3.12
- FFmpeg/FFprobe for video processing
- faster-whisper for transcription
- PySceneDetect for scene detection
- MediaPipe/OpenCV for face tracking
- Provider-based image generation

**Key Capabilities**:
- Audio transcription with word-level timestamps
- Scene detection and segment identification
- Face tracking and analysis
- Clip ranking with AI models
- Vertical reframing with smart cropping
- Caption rendering
- Image generation from text prompts

**Boundaries**:
- Processes jobs from queue, not HTTP requests
- Reads/writes to object storage directly
- Reports results back to Laravel through an explicit application boundary (HTTP API or job completion contract). Laravel owns application persistence; the worker never directly writes to PostgreSQL.
- Stateless, horizontally scalable
- Uses provider abstractions for external AI services

### 4. Infrastructure Services

**Components**:

#### PostgreSQL Database
- Primary data store for application metadata
- Stores users, projects, media metadata, jobs, social connections
- Does NOT store media binaries
- Managed service or Docker container

#### Object Storage (S3-compatible)
- Stores all media files: videos, images, audio, processed clips
- Versioning enabled for media assets
- Lifecycle policies for cost management
- Direct worker access for processing

#### Queue System (Redis/SQS)
- Decouples API from media processing
- Supports job priorities and retries
- Provides job status tracking
- Redis for development, SQS for production

#### CDN (CloudFront/Cloudflare)
- Serves frontend static assets
- Caches public media for faster delivery
- Reduces load on object storage

## Data Flow

### User Interaction Flow

```
User → React Frontend → Laravel API → PostgreSQL
                    ↕
            Object Storage (uploads)
                    ↓
            Queue System (jobs)
                    ↓
            Python Media Worker
                    ↓
            Object Storage (processed media)
                    ↓
            Laravel API (metadata updates)
                    ↓
            React Frontend (status updates)
```

### Media Processing Flow

Long-video clipping does NOT require generative video models. The primary pipeline uses FFprobe, transcription, scene detection, ranking, vertical reframing, and FFmpeg rendering. Generative video models may later be evaluated for optional B-roll but must not block the clipping MVP.

1. **Upload**: User uploads video via frontend → Laravel API → Object Storage
2. **Job Creation**: Laravel creates processing job in queue
3. **Processing**: Worker picks up job, reads from Object Storage
4. **Processing Steps**:
   - Transcription → stores text with timestamps
   - Scene detection → identifies segments
   - Analysis → ranks segments by appeal
   - Reframing → creates vertical clips
   - Captioning → renders text overlays
5. **Result Storage**: Processed clips saved to Object Storage
6. **Metadata Update**: Worker updates job status and clip metadata via API
7. **Notification**: Frontend polls or receives updates

### Image Generation Flow

1. **Request**: User enters prompt → Laravel API validates
2. **Job Creation**: Laravel enqueues generation job
3. **Generation**: Worker sends request to AI provider
4. **Storage**: Generated image saved to Object Storage
5. **Metadata**: Image metadata stored in PostgreSQL
6. **Review**: User reviews and selects images via frontend

## API Boundary

### Communication Protocol

- REST JSON API with `/api/v1` versioning

### Authentication

- Laravel Sanctum SPA authentication
- HTTP-only session cookies for first-party authentication
- CSRF protection via `X-XSRF-TOKEN` header (encrypted cookie)
- Server-side session management via Laravel session driver
- Social OAuth tokens stored encrypted server-side only; these are third-party authorization tokens, not first-party user credentials

**First-Party Authentication vs Social OAuth Authorization**:
- **First-party authentication** (AiClip user login): Sanctum SPA session cookies. User authenticates with email/password. Session is server-side managed.
- **Social OAuth authorization**: User authorizes AiClip to publish on their behalf. These tokens (YouTube, Instagram, TikTok) are stored encrypted server-side and never exposed to the frontend. They are authorization grants, not authentication credentials.

### Key Endpoints

**Authentication**:
- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/verify-email`

**Projects**:
- `GET /api/v1/projects`
- `POST /api/v1/projects`
- `GET /api/v1/projects/{id}`
- `DELETE /api/v1/projects/{id}`

**Media**:
- `POST /api/v1/projects/{id}/media/upload`
- `GET /api/v1/projects/{id}/media`
- `DELETE /api/v1/media/{id}`

**Clips**:
- `GET /api/v1/projects/{id}/clips`
- `POST /api/v1/projects/{id}/clips/{clipId}/approve`
- `PUT /api/v1/clips/{id}`

**Images**:
- `POST /api/v1/images/generate`
- `GET /api/v1/images`
- `GET /api/v1/images/{id}`

**Social**:
- `GET /api/v1/social/connections`
- `POST /api/v1/social/{platform}/connect`
- `DELETE /api/v1/social/{platform}/disconnect`

**Publishing**:
- `POST /api/v1/publish`
- `GET /api/v1/publish/{id}/status`

### Error Handling

- Standard HTTP status codes
- Consistent error response format
- Rate limiting with appropriate headers
- Validation errors with field-specific messages

## Database Entity Overview

### Core Entities

**User**
- Authentication details
- Profile information
- Settings and preferences

**Project**
- Name, description, timestamps
- Owner relationship (User)
- Status (active, archived)

**Media**
- File metadata (size, type, duration)
- Storage path (Object Storage key)
- Processing status
- Parent project relationship

**Clip**
- Derived from Media entity
- Timestamps (start, end)
- Captions text
- Approval status
- Publishing status

**Image**
- Generated from prompts
- Prompt text
- Generation parameters
- Storage path
- Approval status

**Job**
- Processing task metadata
- Status (pending, processing, completed, failed)
- Results and error information
- Related Media entity

**SocialConnection**
- Platform (YouTube, Instagram, TikTok)
- Encrypted access/refresh tokens
- User relationship
- Connection status

**PublishJob**
- Content references (Clip/Image)
- Target platforms
- Publishing status per platform
- Timestamps

### Relationships

- User has many Projects
- Project has many Media
- Media has many Clips (derived)
- Media has many Images (generated)
- User has many SocialConnections
- Project has many Jobs
- Media has one Job (processing)
- Clip has many PublishJobs
- Image has many PublishJobs

## Provider Abstraction Contracts

### 1. TranscriptionProvider

**Interface**: Converts audio to text with timestamps

**Methods**:
- `transcribe(AudioFile) → TranscriptionResult`
- `getSupportedLanguages() → List<Language>`

**Implementations**:
- `WhisperProvider` (primary)
- `FakeTranscriptionProvider` (testing)

### 2. ImageGenerationProvider

**Interface**: Generates images from text prompts

**Methods**:
- `generate(Prompt, Options) → ImageResult`
- `getStylePresets() → List<Style>`

**Implementations**:
- `FluxImageProvider` (primary)
- `SdxlImageProvider` (alternative)
- `FakeImageProvider` (CI and testing)

### 3. ClipRankingProvider

**Interface**: Ranks video segments by appeal

**Methods**:
- `rank(Segments, Context) → RankedClips`
- `getRankingCriteria() → List<Criteria>`

**Implementations**:
- `MLRankingProvider` (primary)
- `FakeRankingProvider` (testing)

### 4. SocialPublisher

**Interface**: Publishes content to social platforms

**Methods**:
- `publish(Content, Platform, Credentials) → PublishResult`
- `getSupportedPlatforms() → List<Platform>`

**Implementations**:
- `YouTubePublisher`
- `InstagramPublisher`
- `TikTokPublisher`
- `FakePublisher` (testing)

### Provider Selection

Providers are selected via configuration, enabling:
- Environment-specific implementations
- Easy testing with fakes
- Future provider changes without domain logic changes

### Development AI vs Product AI

**Development AI**:
- OpenCode models used for agent reasoning (Planner, Builder, Tester)
- These models assist with code generation, specification writing, and test analysis
- They are development tooling, NOT part of the application's runtime

**Product AI**:
- Image generation models (Flux, SDXL) used by the application
- Transcription models (faster-whisper) used by the application
- Clip ranking models used by the application
- These models serve end-users through the application's processing pipeline

Note: OpenCode free development models are NOT the application's product AI backend. Product AI models are invoked through provider abstractions within the Python media worker.

## Security Architecture

### Authentication & Authorization

- **User Authentication**: Laravel Sanctum SPA authentication with HTTP-only session cookies
- **CSRF Protection**: Via encrypted `X-XSRF-TOKEN` cookie
- **Session Management**: Server-side session management via Laravel session driver
- **Password Hashing**: bcrypt with appropriate cost factor
- **Social OAuth**: Server-side flow, tokens never exposed to frontend; authorization grants, not authentication credentials

### Data Protection

- **Encryption at Rest**: Social OAuth tokens encrypted with application key
- **Encryption in Transit**: HTTPS enforced for all communications
- **Input Validation**: Server-side validation on all endpoints
- **Output Encoding**: Proper JSON encoding to prevent XSS

### Isolation & Access Control

- **User Isolation**: All data access scoped to authenticated user
- **Project Isolation**: Users can only access their own projects
- **Media Isolation**: Files stored with user-specific prefixes
- **API Rate Limiting**: Per-user and per-endpoint limits

### Secrets Management

- **Environment Variables**: All secrets in environment config
- **No Hardcoded Secrets**: Never in source code or logs
- **Secret Rotation**: Support for key rotation without downtime
- **Audit Logging**: Track access to sensitive operations

### Network Security

- **Service Communication**: Internal services communicate via private network
- **API Gateway**: Rate limiting, request validation, logging
- **CORS Policy**: Restrict to frontend domain
- **Content Security Policy**: Prevent XSS and injection attacks

## Deployment Topology

### Development Environment

```
Local Machine
├── Docker Compose
│   ├── Laravel API (PHP-FPM)
│   ├── React Frontend (Vite dev server)
│   ├── Python Media Worker
│   ├── PostgreSQL
│   ├── Redis (queue)
│   └── MinIO (S3-compatible storage)
└── Environment: .env.local
```

### Staging Environment

```
Cloud Provider (AWS/GCP/Azure)
├── Frontend: Vercel/Netlify or S3+CloudFront
├── API: Managed PHP hosting (Laravel Forge, Elastic Beanstalk)
├── Worker: Container service (ECS, Cloud Run)
├── Database: Managed PostgreSQL (RDS, Cloud SQL)
├── Queue: Managed Redis (ElastiCache) or SQS
├── Storage: S3 or Cloud Storage
└── Environment: .env.staging
```

### Production Environment

```
Cloud Provider (AWS/GCP/Azure)
├── Frontend: CDN + S3 (static hosting)
├── API: Load-balanced PHP instances
├── Worker: Auto-scaling container service
├── Database: Multi-AZ managed PostgreSQL
├── Queue: Managed Redis cluster or SQS
├── Storage: S3 with versioning
├── Monitoring: CloudWatch/Prometheus
├── Logging: Centralized logging service
└── Environment: .env.production
```

### Deployment Strategy

- **Frontend**: Continuous deployment from main branch
- **API**: Blue-green deployment with zero downtime
- **Worker**: Rolling deployment with job draining
- **Database**: Migrations run before deployment
- **Rollback**: Automated rollback on health check failure

## Scalability Considerations

### Horizontal Scaling

- **Frontend**: CDN scales automatically
- **API**: Add instances behind load balancer
- **Worker**: Scale based on queue depth
- **Database**: Read replicas for query scaling

### Vertical Scaling

- **Database**: Increase instance size for compute
- **Worker**: Increase memory for large video processing
- **API**: Increase CPU for request handling

### Cost Optimization

- **Object Storage**: Lifecycle policies for old media
- **Database**: Right-size instances based on usage
- **Worker**: Spot instances for non-critical processing
- **CDN**: Cache hit optimization

## Monitoring & Observability

### Metrics

- **API**: Request rate, latency, error rate
- **Worker**: Job processing rate, queue depth, failures
- **Database**: Connection pool, query performance
- **Storage**: Usage, request rate, costs

### Logging

- **Structured Logging**: JSON format for machine parsing
- **Correlation IDs**: Track requests across services
- **Error Tracking**: Sentry or similar service
- **Audit Logs**: Track sensitive operations

### Health Checks

- **API**: `/health` endpoint with dependency checks
- **Worker**: Heartbeat reporting to monitoring
- **Database**: Connection and query health
- **Storage**: Connectivity and latency checks

## Disaster Recovery

### Backups

- **Database**: Daily automated backups, point-in-time recovery
- **Object Storage**: Versioning and cross-region replication
- **Configuration**: Infrastructure as code in version control

### Recovery

- **RPO**: 1 hour (database), 0 (object storage with versioning)
- **RTO**: 4 hours for full restore
- **Failover**: Automated for managed services

## Technology Decision Records

Key architectural decisions are documented in:

- `docs/adr/0001-governance-bootstrap.md` - Governance structure
- `docs/adr/0002-mvp-product-and-architecture.md` - MVP architecture decisions

## Future Evolution

This architecture supports evolution through:

1. **Service Extraction**: Any service can be extracted to separate repository
2. **Technology Upgrades**: Provider abstractions enable technology changes
3. **Feature Expansion**: New services can be added without modifying existing
4. **Scale Independent**: Each service scales based on its own demands