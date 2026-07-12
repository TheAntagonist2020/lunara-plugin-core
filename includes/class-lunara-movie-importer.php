<?php
/**
 * Orchestrates local-first Movie previews and explicit draft imports.
 *
 * This service never performs HTTP itself. A provider gateway may be injected
 * for missing local identities; tests use a fixture gateway with the same
 * `get_candidate_by_imdb()` boundary.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Movie_Importer {

    /** @var object */
    private $repository;

    /** @var object|null */
    private $gateway;

    /**
     * Constructor.
     *
     * @param object      $repository Repository exposing plan/apply methods.
     * @param object|null $gateway    Optional provider gateway.
     */
    public function __construct( $repository, $gateway = null ) {
        foreach ( array( 'local_identity_state', 'plan_upsert', 'apply_plan' ) as $method ) {
            if ( ! is_object( $repository ) || ! method_exists( $repository, $method ) ) {
                throw new InvalidArgumentException( 'Movie importer requires a compatible local repository.' );
            }
        }

        if ( null !== $gateway && ( ! is_object( $gateway ) || ! method_exists( $gateway, 'get_candidate_by_imdb' ) ) ) {
            throw new InvalidArgumentException( 'Movie provider gateway must expose get_candidate_by_imdb().' );
        }

        $this->repository = $repository;
        $this->gateway    = $gateway;
    }

    /**
     * Preview an IMDb identity, preferring local WordPress data.
     *
     * Published and otherwise non-draft local matches never invoke the
     * provider gateway. One existing draft may be explicitly enriched through
     * the injected gateway, then normalized and planned without writes.
     *
     * @param mixed $imdb_id IMDb title ID, URL, or text.
     * @return array<string,mixed>
     */
    public function preview_by_imdb( $imdb_id ) {
        $imdb_id = Lunara_Movie_Import_Contract::normalize_imdb_title_id( $imdb_id );
        if ( '' === $imdb_id ) {
            return $this->preview_error( 'invalid', array( 'invalid_imdb_title_id' ) );
        }

        $local            = $this->repository->local_identity_state( $imdb_id );
        $local_draft_plan = null;
        if ( 'conflict' === $local['status'] ) {
            $candidate                  = $local['candidate'];
            $candidate['imdb_title_id'] = $imdb_id;
            $plan                       = $this->repository->plan_upsert( $candidate );

            return $this->preview_envelope( 'conflict', $plan, true, false );
        }

        if ( 'found' === $local['status'] ) {
            $plan = $this->repository->plan_upsert( $local['candidate'] );
            if ( 'draft' !== $plan['post_status'] || null === $this->gateway ) {
                return $this->preview_envelope( 'local', $plan, true, false );
            }

            $local_draft_plan = $plan;
        }

        if ( null === $this->gateway ) {
            return $this->preview_error( 'unavailable', array( 'provider_gateway_unavailable' ) );
        }

        try {
            $response = $this->gateway->get_candidate_by_imdb( $imdb_id );
        } catch ( Exception $exception ) {
            if ( null !== $local_draft_plan ) {
                return $this->preview_envelope( 'local', $local_draft_plan, true, true );
            }
            return $this->preview_error( 'unavailable', array( $exception->getMessage() ) );
        }

        if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
            if ( null !== $local_draft_plan ) {
                return $this->preview_envelope( 'local', $local_draft_plan, true, true );
            }
            $message = method_exists( $response, 'get_error_message' )
                ? $response->get_error_message()
                : 'Provider lookup failed.';
            return $this->preview_error( 'unavailable', array( $message ) );
        }

        if ( isset( $response['candidate'] ) && is_array( $response['candidate'] ) ) {
            $response = $response['candidate'];
        }

        if ( ! is_array( $response ) ) {
            if ( null !== $local_draft_plan ) {
                return $this->preview_envelope( 'local', $local_draft_plan, true, true );
            }
            return $this->preview_error( 'unavailable', array( 'provider_candidate_unavailable' ) );
        }

        $candidate = Lunara_Movie_Import_Contract::normalize_candidate( $response );
        if ( $candidate['imdb_title_id'] !== $imdb_id ) {
            if ( null !== $local_draft_plan ) {
                return $this->preview_envelope( 'local', $local_draft_plan, true, true );
            }
            return $this->preview_error( 'conflict', array( 'provider_identity_mismatch' ) );
        }

        $preview                 = $this->preview_candidate( $candidate );
        $preview['gateway_used'] = true;
        if ( null !== $local_draft_plan ) {
            if ( 'conflict' === $preview['status'] ) {
                $preview['local'] = true;
                return $preview;
            }
            if ( 'invalid' === $preview['status'] || 'draft' !== $preview['plan']['post_status'] ) {
                return $this->preview_envelope( 'local', $local_draft_plan, true, true );
            }
            $preview['local'] = true;
        }

        return $preview;
    }

    /**
     * Preview a normalized provider candidate without performing writes.
     *
     * @param array<string,mixed> $candidate Candidate or fixture.
     * @param array<string,mixed> $context   Optional review/role provenance.
     * @return array<string,mixed>
     */
    public function preview_candidate( $candidate, $context = array() ) {
        $plan   = $this->repository->plan_upsert( $candidate, $context );
        $status = 'ready';

        if ( 'invalid' === $plan['action'] ) {
            $status = 'invalid';
        } elseif ( 'conflict' === $plan['action'] ) {
            $status = 'conflict';
        }

        return $this->preview_envelope( $status, $plan, false, false );
    }

    /**
     * Explicitly create or fill a local Movie draft from one candidate.
     *
     * The method does not call the gateway. Callers must preview/select the
     * candidate first, making the write a deliberate admin action.
     *
     * @param array<string,mixed> $candidate Candidate or fixture.
     * @param array<string,mixed> $context   Optional review/role provenance.
     * @return array<string,mixed>
     */
    public function import_draft( $candidate, $context = array() ) {
        $preview = $this->preview_candidate( $candidate, $context );
        if ( in_array( $preview['status'], array( 'invalid', 'conflict' ), true ) ) {
            return array(
                'status'           => $preview['status'],
                'movie_id'         => 0,
                'writes_performed' => 0,
                'plan_hash'        => $preview['plan_hash'],
                'plan'             => $preview['plan'],
                'issues'           => $preview['issues'],
            );
        }

        return $this->repository->apply_plan( $preview['plan'] );
    }

    /**
     * Apply a previously returned preview plan after repository revalidation.
     *
     * @param array<string,mixed> $plan Sealed plan from preview_candidate().
     * @return array<string,mixed>
     */
    public function apply_plan( $plan ) {
        return $this->repository->apply_plan( $plan );
    }

    /**
     * Build a successful preview response.
     *
     * @param string              $status Status code.
     * @param array<string,mixed> $plan   Sealed plan.
     * @param bool                $local   Whether local data satisfied lookup.
     * @param bool                $gateway Whether the gateway was called.
     * @return array<string,mixed>
     */
    private function preview_envelope( $status, $plan, $local, $gateway ) {
        return array(
            'status'           => $status,
            'action'           => $plan['action'],
            'local'            => (bool) $local,
            'gateway_used'     => (bool) $gateway,
            'movie_id'         => (int) $plan['movie_id'],
            'candidate'        => $plan['candidate'],
            'plan'             => $plan,
            'plan_hash'        => $plan['plan_hash'],
            'writes_performed' => 0,
            'issues'           => $plan['issues'],
        );
    }

    /**
     * Build a preview failure without constructing a writable plan.
     *
     * @param string            $status Status code.
     * @param array<int,string> $issues Issues or errors.
     * @return array<string,mixed>
     */
    private function preview_error( $status, $issues ) {
        return array(
            'status'           => $status,
            'action'           => 'none',
            'local'            => false,
            'gateway_used'     => false,
            'movie_id'         => 0,
            'candidate'        => array(),
            'plan'             => array(),
            'plan_hash'        => '',
            'writes_performed' => 0,
            'issues'           => array_values( array_unique( $issues ) ),
        );
    }
}
