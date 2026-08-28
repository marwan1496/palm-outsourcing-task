.PHONY: help run build test test-race cover fmt vet lint tidy clean

BINARY := bin/proxyd
PKG    := ./...

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

run: ## Run the service against proxies.yaml
	go run ./cmd/proxyd

build: ## Compile the binary into bin/
	go build -o $(BINARY) ./cmd/proxyd

test: ## Run all tests with coverage
	go test -cover $(PKG)

test-race: ## Run tests under the race detector (needs CGO + a C compiler)
	CGO_ENABLED=1 go test -race $(PKG)

cover: ## Write and open an HTML coverage report
	go test -coverprofile=coverage.out $(PKG)
	go tool cover -html=coverage.out

fmt: ## Format all Go source
	go fmt $(PKG)

vet: ## Run the built-in static analyser
	go vet $(PKG)

lint: fmt vet test ## Format, vet and test in one go

tidy: ## Sync go.mod / go.sum with the imports actually used
	go mod tidy

clean: ## Remove build artefacts
	rm -rf bin coverage.out
