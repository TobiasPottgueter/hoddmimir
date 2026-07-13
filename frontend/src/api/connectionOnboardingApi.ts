import { createClient, createConfig } from "@/api/generated/client";
import { withHttpStatus } from "@/api/errors";
import {
  activateOnboardedConnection,
  addVerifiedConnectionEndpoint,
  getConnectionOnboardingGuidance,
  rotateOnboardedConnectionCredentials,
  updateVerifiedConnectionEndpoint,
} from "@/api/generated/sdk.gen";
import type {
  OnboardingActivationRequestWritable,
  OnboardingEndpointMutationRequestWritable,
  OnboardingGuidance,
  OnboardingMutationResult,
  OnboardingProduct,
  OnboardingRotationRequestWritable,
} from "@/api/generated/types.gen";

export interface OnboardingHeaders {
  csrfToken: string;
  idempotencyKey: string;
}

export interface ConnectionOnboardingApi {
  guidance(product: OnboardingProduct): Promise<OnboardingGuidance>;
  activate(
    body: OnboardingActivationRequestWritable,
    headers: OnboardingHeaders,
  ): Promise<OnboardingMutationResult>;
  rotate(
    connectionId: string,
    body: OnboardingRotationRequestWritable,
    headers: OnboardingHeaders,
  ): Promise<OnboardingMutationResult>;
  addEndpoint(
    connectionId: string,
    body: OnboardingEndpointMutationRequestWritable,
    headers: OnboardingHeaders,
  ): Promise<OnboardingMutationResult>;
  updateEndpoint(
    connectionId: string,
    endpointId: string,
    body: OnboardingEndpointMutationRequestWritable,
    headers: OnboardingHeaders,
  ): Promise<OnboardingMutationResult>;
}

const commandHeaders = (value: OnboardingHeaders) => ({
  "Idempotency-Key": value.idempotencyKey,
  "X-CSRF-Token": value.csrfToken,
});

export function createConnectionOnboardingApi(
  baseUrl = typeof window === "undefined"
    ? "http://localhost"
    : window.location.origin,
): ConnectionOnboardingApi {
  const client = createClient(createConfig({ baseUrl }));
  client.interceptors.error.use((error, response) =>
    withHttpStatus(error, response),
  );
  return {
    async guidance(product) {
      return (
        await getConnectionOnboardingGuidance({
          client,
          path: { product },
          throwOnError: true,
        })
      ).data;
    },
    async activate(body, headers) {
      return (
        await activateOnboardedConnection({
          client,
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async rotate(connectionId, body, headers) {
      return (
        await rotateOnboardedConnectionCredentials({
          client,
          path: { id: connectionId },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async addEndpoint(connectionId, body, headers) {
      return (
        await addVerifiedConnectionEndpoint({
          client,
          path: { id: connectionId },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
    async updateEndpoint(connectionId, endpointId, body, headers) {
      return (
        await updateVerifiedConnectionEndpoint({
          client,
          path: { id: connectionId, endpointId },
          body,
          headers: commandHeaders(headers),
          throwOnError: true,
        })
      ).data;
    },
  };
}

export const connectionOnboardingApi = createConnectionOnboardingApi();
