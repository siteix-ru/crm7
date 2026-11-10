<script lang="ts">
  interface Props {
    width?: string;
    height?: string;
    radius?: string;
  }

  let { width = '100%', height = '14px', radius = 'var(--radius-sm)' }: Props = $props();
</script>

<span
  class="skeleton"
  style:width
  style:height
  style:border-radius={radius}
  aria-hidden="true"
></span>

<style>
  .skeleton {
    display: block;
    background: linear-gradient(
      90deg,
      var(--fill-tertiary) 25%,
      rgba(0, 0, 0, 0.07) 37%,
      var(--fill-tertiary) 63%
    );
    background-size: 400% 100%;
    animation: shimmer 1.4s ease infinite;
  }

  @keyframes shimmer {
    0% {
      background-position: 100% 50%;
    }

    100% {
      background-position: 0 50%;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .skeleton {
      animation: none;
    }
  }
</style>
